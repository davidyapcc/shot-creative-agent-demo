# Domain Docs

This is a single-context repo: one `CONTEXT.md` (the glossary) and `docs/adr/` (decisions), both at the repo root.

## Before exploring, read these

- **`CONTEXT.md`** — the glossary.
- **`docs/adr/`** — the ADRs that touch the area you're about to work in.

Neither exists yet. When they're missing, proceed silently: the `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates them lazily, as terms and decisions actually get resolved.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as `CONTEXT.md` defines it, and keep to that term wherever the glossary lists alternatives to avoid.

A concept missing from the glossary is a signal. Either you're inventing language the project doesn't use (reconsider), or there's a real gap (note it for `/domain-modeling`).

## Flag ADR conflicts

When your output contradicts an existing ADR, say so explicitly:

> _Contradicts ADR-0007 (event-sourced orders) — but worth reopening because…_
