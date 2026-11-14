# Shot Creative Agent

An agent that plans a creative brief into shots, generates each one, and has a judge model check the result. The eval suite judges the judge.

## Language

### Evals

**Golden set**:
A fixed collection of golden clips with human labels, used to measure how well a judge agrees with people.
_Avoid_: Test set, benchmark

**Golden clip**:
One video in the golden set, stored with the prompt it was meant to show.
_Avoid_: Sample, eval clip

**Human label**:
A person's verdict (pass or fail) and failure tags for one golden clip. It is the ground truth a judge is scored against.
_Avoid_: Annotation, ground-truth verdict

**Eval run**:
One pass of a single judge configuration over a set, with its per-clip verdicts, metrics and spend.
_Avoid_: Benchmark run, eval job

**Brief set**:
A fixed collection of briefs covering hard subjects (people, hands, vehicles, text on signs, multi-shot continuity), kept for end-to-end agent runs.
_Avoid_: Test briefs, prompt set

**Judge**:
The model that gives a clip a pass or fail verdict, with failure tags, against the rubric.
_Avoid_: Grader, critic

**Judge agreement**:
The share of golden clips on which two eval runs with different judges give the same verdict, counting only clips both judges gave a verdict on.
_Avoid_: Inter-rater reliability, consensus

### Paying for provider work

**Paid call**:
A single call to a provider that is charged to the brief's budget before it runs.
_Avoid_: Provider request, metered call

**Shot step**:
A paid call made on behalf of one shot. Shot steps for different shots run in parallel.
_Avoid_: Shot job, shot task

**Brief step**:
A paid call made on behalf of the whole brief, such as planning or audio.
_Avoid_: Brief job, stage call

**Claim**:
A reservation taken on one-shot work before its paid call, so a retry or an overlapping run never pays for it twice.
_Avoid_: Lock, reservation flag

### Stopping

**Budget stop**:
The moment a brief reaches its budget cap: every shot that has not passed is failed and the brief finishes on the clips it already has.
_Avoid_: Budget exhaustion, cap hit

**Live**:
Still eligible to receive the result of a paid call. A shot is live until it is failed; a brief is live for a brief step while it remains in that step's stage.
_Avoid_: Active, in-flight

**Late result**:
The result of a paid call that returns after its shot or brief stopped being live. A late result is dropped.
_Avoid_: Stale result, orphaned result
