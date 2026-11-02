# Brief → Shot: Creative Agent Demo Spec

Nov 3, 2026 · @David

## Purpose

A standalone \~21h demo that proves the "shared evaluations and live quality monitoring" and "async long-running generation" lines of the ElevenLabs Full-Stack Engineer (Backend Leaning) – Creative Agents role (career-ops #82).

| Job-post line | How the demo proves it |
| --- | --- |
| Production agents: prompts, tool use, orchestration, MCP | LLM agent plans a brief into shots and calls tools; same tools exposed via an MCP server |
| Async creative generation and long-running tasks | Queued image and video jobs that poll providers without blocking workers, with live progress |
| Shared evaluations + model-based assessment of image/video | Fixed rubric, golden set, eval CLI with run-vs-run diff and two-judge agreement |
| Live quality monitoring | Dashboard of pass rate, failure tags, retries, latency, cost |
| HTTP, WebSockets, async workers, failure recovery | Reverb progress stream, retries, rate-limit backoff, budget guard |
| End-to-end ownership incl. frontend | Vue UI for briefs, live progress and results |

[Job post](https://elevenlabs.io/pt/careers/35813150-a851-4821-b732-a037b4e6c4fe/full-stack-engineer-backend-leaning-creative-agents)

## What it does

A user submits a creative brief; an agent turns it into 2–3 short video shots, judges every output, and retries failures within a budget.

1. `POST /briefs` with a brief, e.g. "15s product teaser, rainy Tokyo, neon".
2. Agent plans 2–3 shots, then generates style-anchored keyframes first so every shot shares one look; video renders then run in parallel instead of blocking each other.
3. Per shot: `generate_image` → `judge` → `image_to_video` → `judge`.
4. On a judge fail, the agent rewrites the prompt from the judge's output and retries. Max 2 retries per step; stops on budget cap and marks the brief `degraded`.
5. Per brief: `generate_audio` creates a voiceover (ElevenLabs v4 Turbo) and one ambient sound effect, generated once and never regenerated on a retry.
6. ffmpeg muxes the passing clips and audio into one final \~15s MP4.
7. Progress streams to the UI: `planning → keyframes → judging (fail: anatomy) → regenerating → video 40% → audio → muxing → done`.
8. Outputs land in S3 (MinIO locally); scores and metrics land in Postgres.

Out of scope: user accounts, editing tools, more than 3 shots, lip-sync, multi-track audio editing.

## Architecture and stack

Laravel with Redis queues, chosen because it is the fastest stack to ship in 20h; the patterns are language-agnostic and the README says so.

| Concern | Choice |
| --- | --- |
| Orchestration | A state machine on `Brief` / `Shot` drives `Bus::chain` / `Bus::batch` jobs deterministically |
| Agent (LLM) | Prism, invoked only at decision nodes: Plan Shots and Rewrite Prompt on Fail |
| Queues + observability | Redis queues, Horizon; separate `generate`, `poll`, `judge`, `audio` queues (`audio` at concurrency 1–2) |
| Long-running video | Submit once, then a poll job that `release(10)`s itself until done (never sleeps a worker) |
| Progress | Reverb (WebSockets) broadcast events, Echo in Vue |
| MCP | `laravel/mcp` exposing async-safe `submit_shot`, `get_shot_status`, `judge_media` so clients never block on a 90s render |
| Final assembly | ffmpeg muxes passing clips + audio into one MP4 |
| Storage | `Storage::disk('s3')`, MinIO in docker-compose |
| Data | Postgres: briefs, shots, judgements, eval\_runs |
| Frontend | Vue + Echo |
| Run | `docker compose up`, Replay/Mock providers by default, no keys needed |

&#91;embedded content: architecture · request path and worker fan-out\]

The API only enqueues; workers call providers and the judge, write to Postgres and MinIO, and push progress to the UI through Reverb.

## Model-agnostic providers

Every model call goes through an interface chosen in config, so swapping Gemini, OpenAI or anything else is an `.env` change.

| Role | Interface | Default (no keys) | Real drivers in `.env.example` |
| --- | --- | --- | --- |
| Agent LLM | Prism provider | Mock | Gemini 3.8 Flash (free) · OpenAI · Cloudflare Llama |
| Judge | `Judge` | Replay (recorded verdicts) | Gemini 3.5 Flash-Lite, native video (free) · OpenAI · frame-sampling fallback |
| Image | `ImageProvider` | Replay | Cloudflare FLUX (free) · OpenAI |
| Video | `VideoProvider` | Replay · ffmpeg motion | Vertex Veo (GCP trial) |
| Audio | `AudioProvider` | Replay | ElevenLabs v4 Turbo TTS + Sound Effects (free tier) · Gemini 3.8 Flash-Lite TTS (check free-tier status) |

```php
// config/creative.php — defaults run with no keys; real drivers set in .env
return [
    'agent' => ['provider' => env('AGENT_PROVIDER', 'mock'),   'model' => env('AGENT_MODEL', 'gemini-3.8-flash')],
    'judge' => ['provider' => env('JUDGE_PROVIDER', 'replay'), 'model' => env('JUDGE_MODEL', 'gemini-3.5-flash-lite')],
    'image' => env('IMAGE_PROVIDER', 'replay'),
    'video' => env('VIDEO_PROVIDER', 'replay'),
    'audio' => env('AUDIO_PROVIDER', 'replay'),
];
```

The OpenAI key is paid, so it sits behind the same per-brief budget guard as every other driver.

## Judge rubric

An output passes when every dimension scores 3 or more and no hard-fail tag is set.

| Dimension (1–5) | Checks | Applies to |
| --- | --- | --- |
| Prompt adherence | Matches the brief and shot plan | Image, video |
| Anatomy | Hands, faces, limbs correct | Image, video |
| Object integrity | Objects keep their shape (no warped wings, melted props) | Image, video |
| Temporal consistency | No flicker or morphing between frames | Video |

Hard-fail tags: `anatomy`, `scene_mismatch`, `object_warp`, `temporal_flicker`, `text_artifact`.

```json
{
  "shot_id": 42,
  "scores": {"prompt_adherence": 4, "anatomy": 2, "object_integrity": 5, "temporal_consistency": 3},
  "hard_fail_tags": ["anatomy"],
  "reason": "Left hand has six fingers from 0:02 onward.",
  "verdict": "fail"
}
```

The judge returns this as structured output; the dashboard groups by tag.

Transport and sampling:

- Clips (\~5s) are compressed to under 10MB and sent inline, well under the 20MB inline request limit, so there is no second polling loop on the Files API ([video understanding](https://ai.google.dev/gemini-api/docs/video-understanding)).
- Gemini samples video at 1 frame per second by default, so a 5s clip is only 5 frames. Set a custom `fps` of 4–8 in static mode, or `temporal_consistency` and flicker can't be judged reliably.

Rewrite on fail:

- The prompt rewriter receives `{brief, original_prompt, failed_scores, reason, previous_attempts}`, where `previous_attempts` holds each earlier prompt and its reason.
- It is instructed to preserve scene invariants, simplify only the failing element, and not repeat a fix that already failed.

## Shared evals

The eval suite measures the judge itself against human labels, so any model or prompt change can be compared run to run.

- **Golden set:** \~12 fresh, neutral clips generated with Veo trial credits, roughly half passing and half failing, failures kept on purpose. Stored as files in MinIO plus `evals/golden.json` (human verdict + failure tags per clip).
- **Brief set:** \~15 fixed briefs (people, hands, vehicles, text on signs, multi-shot continuity) for end-to-end agent runs.
- **CLI:** `php artisan eval:run --judge=gemini --set=golden` writes an `eval_run` with per-clip verdicts.
- **Metrics per run:** judge precision and recall vs human labels, per-tag recall, pass rate, cost units.
- **Diff:** `php artisan eval:diff {runA} {runB}` shows what changed between two configs.
- **Two-judge agreement:** run the same golden set through Gemini and OpenAI judges; report agreement rate and where they disagree. Addresses same-family bias (Veo judged by Gemini).

## Live monitoring

One Vue page, updated over Reverb, shows whether generation quality is holding right now.

| Metric | Source |
| --- | --- |
| Judge pass rate (last 50 outputs) | judgements table |
| Top failure tags | judgements.hard\_fail\_tags |
| Retries per brief | shots.attempts |
| Brief duration p50 / p95 | briefs created\_at → completed\_at |
| Cost units per brief | Budget guard ledger |
| Queue depth and failed jobs | Horizon |
| Rate-limit hits (429s) | Job middleware counter |

Horizon stays the ops view for queues; this page is the quality view.

## Zero-cost constraints

Everything runs at $0 except optional OpenAI calls; video is the only piece that needs the one-time GCP trial.

| Service | Free allowance | Gotcha |
| --- | --- | --- |
| Gemini API free tier ([pricing](https://ai.google.dev/gemini-api/docs/pricing)) | Free tokens on Flash / Flash-Lite | Content used to improve Google products; limits per project, not fixed ([rate limits](https://ai.google.dev/gemini-api/docs/rate-limits)); daily reset at midnight Pacific (3–4pm MYT) |
| Cloudflare Workers AI ([pricing](https://developers.cloudflare.com/workers-ai/platform/pricing/)) | 10,000 Neurons/day, \~170 FLUX-schnell images | Resets 00:00 UTC (8am MYT) |
| ElevenLabs free tier ([pricing](https://elevenlabs.io/pricing/api)) | 20,000 chars of v4 Turbo TTS (\~20 min) or 10,000 chars of v4; only 8 sound-effect generations per month | Free tier is disabled if abuse detection flags a VPN or shared IP ([help](https://elevenlabs.io/docs/help-center/account/general/what-does-unusual-activity-detected-free-tier-usage-disabled-if-you-are-using-proxy-vpn-you-might-need-to-purchase-a-paid-plan-to-not-trigger-our-abuse-detectors-mean)); TTS concurrency limit unverified |
| Google Cloud trial ([free](https://cloud.google.com/free/?hl=cy)) | $300 credit, 90 days, card on file | Clock starts at signup; set a budget alert; keep it a separate project from the free Gemini key |
| ffmpeg, MinIO, Redis, Postgres | Local | None |

Rules:

- Never link billing to the Gemini free-tier project; it moves the project to a paid tier.
- Sign up for the GCP trial only after the pipeline runs end to end on Replay and ffmpeg.
- Run live ElevenLabs calls only from your home connection (no VPN, no cloud VM), and save every output into Replay fixtures immediately.
- Generate one sound effect per brief and never regenerate it on a retry; 8 per month is the hard cap.
- Free tiers return 429s: judge, generate and audio jobs use `RateLimited` and `ThrottlesExceptions` middleware with `retryUntil` of 2h.
- A per-brief `Budget` guard meters every provider call, including free ones.

## Build order (\~21h)

Build the async spine on free mock providers first; real models and the GCP trial come in only once the pipeline works end to end.

- [ ] **Scaffold (2h):** docker-compose (Laravel, Redis, Horizon, Reverb, MinIO, Postgres), migrations for briefs, shots, judgements, eval\_runs, plus the Brief/Shot state machine.
- [ ] **Providers + audio (4h):** interfaces with Replay/Mock defaults, ffmpeg motion video, Cloudflare FLUX image, ElevenLabs `AudioProvider` (voiceover + one SFX), ffmpeg muxer.
- [ ] **Async pipeline (3h):** keyframes first, parallel shot renders, submit + self-releasing poll job, S3 writes, Reverb progress events.
- [ ] **Judge (2.5h):** Gemini Flash-Lite with inline video and custom fps, rubric as structured output, rate-limit middleware. Test the rubric now on 4–6 good and bad sample clips (a few made by hand in Flow), not at hour 16.
- [ ] **Agent loop (3h):** Prism at Plan Shots and Rewrite Prompt on Fail, judge-driven retries (max 2) with previous attempts, budget guard.
- [ ] **UI + dashboard (1.5h):** one page, split view: brief + live stream on the left, shot cards with a quality telemetry header on the right.
- [ ] **MCP server (1h):** async-safe `submit_shot`, `get_shot_status`, `judge_media`.
- [ ] **Evals (2.5h):** start the GCP trial, add the Vertex Veo driver, generate and label the golden set, `eval:run`, `eval:diff`, two-judge agreement.
- [ ] **Ship (1.5h):** record one genuine real-provider run as the default Replay fixture, README with Mermaid state diagram, 2–3 min demo video.

If time runs short, cut in this order: MCP server, dashboard polish, two-judge agreement. Never cut the judge or the eval CLI.

## Deliverables and README talking points

A reviewer should clone the repo, run `docker compose up`, and see a brief complete on mock providers within 5 minutes, with no API keys.

Deliverables:

- Standalone public repo, linked from the main portfolio project.
- Default Replay fixture: a recording of a genuine Veo + ElevenLabs run where one shot fails on attempt 1, the prompt is rewritten, and it passes on attempt 2. Labeled as a replay; if no shot fails naturally, rerun until one does, and never stage the failure.
- 2–3 min demo video of a live run: the judge catching an artifact, the agent retrying, audio muxed into the final MP4, the dashboard updating.
- `evals/REPORT.md` from the latest run: judge precision/recall, per-tag recall, two-judge agreement.

README talking points:

- A Mermaid state-machine diagram of the queue / poll / judge flow, so Python and TypeScript reviewers can follow it without reading PHP.
- Why Laravel: fastest stack to ship in \~21h; the state machine, polling and judging patterns transfer to any language.
- Deterministic orchestration; the LLM is called only at the two decision nodes.
- Workers never sleep on long renders: submit, then self-releasing poll jobs.
- The judge is evaluated too, against a labeled golden set and a second judge model.
- Replay and ffmpeg providers are labeled as such; free-tier data use is disclosed.
- Every provider call is metered against a per-brief budget, even at $0.
