# Agent instructions

- **Issues** — GitHub Issues on davidyapcc/shot-creative-agent-demo, via `gh`. Before you create, read, label or close an issue, read `docs/agents/issue-tracker.md`.
- **Branch per issue** — before the first edit for an issue, cut a fresh branch from an up-to-date `main`, named `<type>/<short-slug>` with the commit types (`feat/gemini-openai-judges`). One issue per branch.
- **Triage labels** — `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. Each skill role name is also the label string.
- **Domain vocabulary** — before you name a domain concept or change an area with a past decision behind it, read `docs/agents/domain.md`.
- **Spec** — `Brief → Shot Creative Agent Demo Spec v2.md` is the current spec; the unsuffixed file is the earlier draft.

## PHP style

Pint's Laravel preset (`pint.json`) is the standard. Run `composer format` on the PHP you touch, and commit only with `composer lint` green.

## Paid-call discipline

Every provider call is metered against the brief budget, and every one except a render poll spends it. Eval runs carry their own budget (`docs/adr/0001-eval-runs-carry-their-own-budget.md`).

Make every paid call through `App\Services\PaidCall` (`forShot` for a shot step, `forBrief` for a brief step). It owns the order: charge, claim, call, refund on a 429, re-check under a row lock, then apply or drop the late result. Only `PaidCall` calls `Budget` to charge or refund; `PaidCallOwnershipTest` goes red otherwise.

Using the module, you still have to:

- **Write only in `apply`.** Database writes go in the `apply` closure, on the locked row it hands you. Broadcast and dispatch after the call returns, and only when the result `wasApplied()`. A state move before the call goes through `Shot::advanceIfAllowed`, never a stale `transitionTo`.
- **Claim one-shot work.** Pass a `PaidCallClaim` whose `take` and `release` set and clear the step's own marker (as `GenerateAudioJob::claim` does), so a retry or an overlapping job never pays twice.
- **Count a failed generation.** Pass `onFailure` to record the attempt; `shots.attempts` counts every generation, image and video together.

Outside the module:

- **Polls are metered, never charged.** Only the video submit spends budget. Log each poll at zero cost with `Budget::meterFreeShotCall`; on `false`, stop polling. A finished render drops its late result with `Shot::advanceUnlessStopped`.
- **Fail early.** Once no shot can pass, fail the brief before the next stage spends anything.
- `shots.prompt` holds the latest rewrite; rewrites read the planned scene from `metadata['original_prompt']`.

## State names

The PHP enums (`BriefState`, `ShotState`, `JudgementVerdict`) are the single source of truth for the Vue side. After changing one, run `php artisan frontend:export-states` and commit `resources/js/pipeline-states.json`; `FrontendEnumCatalogTest` goes red on drift.

## Environment traps

- Tests run on in-memory SQLite with a sync queue, where `lockForUpdate` locks nothing. A green suite proves the logic of a race fix, never the locking; prove locking against the compose stack's Postgres.
- `docker compose up` must reach `done` on Replay/Mock providers with no API keys — the reviewer's first run. Keep every provider default keyless.
- S3 writes to a missing MinIO bucket fail silently and surface later as `Passing clip missing` in `MuxFinalVideoJob`. Create any new bucket in the `minio-init` service.
- Inside the containers, config comes from the compose env; `.env` holds host values (`127.0.0.1`). Keep `artisan serve --no-reload` so the web process keeps the container env.
- Replay fixtures are recordings of genuine provider runs. Re-record a run to capture a failure; leave recorded output untouched.
