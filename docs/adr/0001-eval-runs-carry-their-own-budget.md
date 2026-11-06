# Eval runs carry their own budget

Every provider call in the pipeline spends a brief's budget, but an eval run judges golden clips with no brief behind it. We gave each eval run its own cap (`--budget`, defaulting to one unit per clip) and its own ledger on the eval run, charged before each judge call, Replay included, so the run stops early and is marked partial when the cap is reached. Two-judge agreement and any later eval command reuse the same guard.

## Considered Options

- **A synthetic brief per eval run**, reusing `Budget` unchanged. Rejected: it fills the briefs table and the live quality dashboard with runs that are not briefs.
- **No metering for evals.** Rejected: real judges cost money, and "every provider call is metered, even at $0" is a promise the demo makes to reviewers.
