# Brief → Shot: Creative Agent Demo Spec

Nov 3, 2026 · @David

## Purpose

A standalone \~20h demo that proves the "shared evaluations and live quality monitoring" and "async long-running generation" lines of the ElevenLabs Full-Stack Engineer (Backend Leaning) – Creative Agents role (career-ops #82).

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
2. Agent plans 2–3 shots with continuity notes (start/end frames shared between shots).
3. Per shot: `generate_image` → `judge` → `image_to_video` → `judge`.
4. On a judge fail, the agent rewrites the prompt from the judge's `reason` and retries. Max 2 retries per step; stops on budget cap and marks the brief `degraded`.
5. Progress streams to the UI: `planning → image 2/3 → judging (fail: anatomy) → regenerating → video 40% → done`.
6. Outputs land in S3 (MinIO locally); scores and metrics land in Postgres.

Out of scope: user accounts, editing tools, more than 3 shots, audio.

## Architecture and stack

Laravel with Redis queues, chosen because it is the fastest stack to ship in 20h; the patterns are language-agnostic and the README says so.

| Concern | Choice |
| --- | --- |
| API + orchestration | Laravel, `Bus::chain` / `Bus::batch` per brief |
| Queues + observability | Redis queues, Horizon; separate `generate`, `poll`, `judge` queues |
| Long-running video | Submit once, then a poll job that `release(10)`s itself until done (never sleeps a worker) |
| Progress | Reverb (WebSockets) broadcast events, Echo in Vue |
| Agent tool-calling | Prism PHP |
| MCP | `laravel/mcp` exposing `generate_image`, `image_to_video`, `judge` |
| Storage | `Storage::disk('s3')`, MinIO in docker-compose |
| Data | Postgres: briefs, shots, judgements, eval\_runs |
| Frontend | Vue + Echo |
| Run | `docker compose up`, mock providers by default |

&#91;embedded content: architecture · request path and worker fan-out\]

The API only enqueues; workers call providers and the judge, write to Postgres and MinIO, and push progress to the UI through Reverb.

## Model-agnostic providers

Every model call goes through an interface chosen in config, so swapping Gemini, OpenAI or anything else is an `.env` change.

| Role | Interface | Drivers (default first) |
| --- | --- | --- |
| Agent LLM | Prism provider | Gemini 3.8 Flash (free) · OpenAI · Cloudflare Llama |
| Judge | `Judge` | Gemini 3.5 Flash-Lite, native video (free) · OpenAI · frame-sampling fallback |
| Image | `ImageProvider` | Cloudflare FLUX (free) · OpenAI · Replay · Mock |
| Video | `VideoProvider` | ffmpeg motion (free) · Vertex Veo (GCP trial) · Replay · Mock |

```php
// config/creative.php
return [
    'agent' => ['provider' => env('AGENT_PROVIDER', 'gemini'), 'model' => env('AGENT_MODEL', 'gemini-3.8-flash')],
    'judge' => ['provider' => env('JUDGE_PROVIDER', 'gemini'), 'model' => env('JUDGE_MODEL', 'gemini-3.5-flash-lite')],
    'image' => env('IMAGE_PROVIDER', 'cloudflare'),
    'video' => env('VIDEO_PROVIDER', 'ffmpeg'),
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

The judge returns this as structured output; the agent reads `reason` to rewrite its prompt, and the dashboard groups by tag.

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
| Google Cloud trial ([free](https://cloud.google.com/free/?hl=cy)) | $300 credit, 90 days, card on file | Clock starts at signup; set a budget alert; keep it a separate project from the free Gemini key |
| ffmpeg, MinIO, Redis, Postgres | Local | None |

Rules:

- Never link billing to the Gemini free-tier project; it moves the project to a paid tier.
- Sign up for the GCP trial only after the pipeline runs end to end on ffmpeg and Replay.
- Free tiers return 429s: judge and generate jobs use `RateLimited` and `ThrottlesExceptions` middleware with `retryUntil` of 2h.
- A per-brief `Budget` guard meters every provider call, including free ones.

## Build order (20h)

Build the async spine on free mock providers first; real models and the GCP trial come in only once the pipeline works end to end.

- [ ] **Scaffold (2h):** docker-compose (Laravel, Redis, Horizon, Reverb, MinIO, Postgres), migrations for briefs, shots, judgements, eval\_runs.
- [ ] **Providers (2.5h):** interfaces + Mock, ffmpeg motion, Replay video; Cloudflare FLUX image.
- [ ] **Async pipeline (3h):** per-shot chain, submit + self-releasing poll job, S3 writes, Reverb progress events.
- [ ] **Judge (2h):** Gemini Flash-Lite with native video, rubric as structured output, rate-limit middleware.
- [ ] **Agent loop (3h):** Prism tool-calling, shot planning, judge-driven retries (max 2), budget guard.
- [ ] **UI + dashboard (2.5h):** Vue brief form, live progress, results, quality metrics.
- [ ] **MCP server (1h):** expose `generate_image`, `image_to_video`, `judge`.
- [ ] **Evals (2.5h):** start the GCP trial, add the Vertex Veo driver, generate and label the golden set, `eval:run`, `eval:diff`, two-judge agreement.
- [ ] **Ship (1.5h):** one real Veo brief end to end, README, 2–3 min demo video.

If time runs short, cut in this order: MCP server, two-judge agreement, dashboard polish. Never cut the judge or the eval CLI.

## Deliverables and README talking points

A reviewer should clone the repo, run `docker compose up`, and see a brief complete on mock providers within 5 minutes, with no API keys.

Deliverables:

- Standalone public repo, linked from the main portfolio project.
- 2–3 min demo video: a real Veo brief, the judge catching an artifact, the agent retrying, the dashboard updating.
- `evals/REPORT.md` from the latest run: judge precision/recall, per-tag recall, two-judge agreement.

README talking points:

- Why Laravel: fastest stack to ship in 20h; queue, poll and judge patterns transfer to any language.
- Workers never sleep on long renders: submit, then self-releasing poll jobs.
- The judge is evaluated too, against a labeled golden set and a second judge model.
- Replay and ffmpeg providers are labeled as such; free-tier data use is disclosed.
- Every provider call is metered against a per-brief budget, even at $0.
