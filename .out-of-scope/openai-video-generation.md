# OpenAI Video Generation

This project has no OpenAI `VideoProvider` driver (Sora or any other OpenAI video model).

## Why this is out of scope

OpenAI no longer offers a video generation API. Its deprecations page lists the Videos API, `sora-2`, `sora-2-pro` and their dated snapshots with a shutdown date of 2026-09-24 and no recommended replacement. The consumer Sora app closed earlier, in April 2026. A driver would have nothing to call.

The `VideoProvider` contract is image-to-video, with `submit` followed by a self-releasing `poll`:

```php
interface VideoProviderInterface
{
    public function submit(string $prompt, string $keyframePath): string;

    public function poll(string $renderId): VideoRenderResult;
}
```

Any provider that offers async image-to-video fits that contract. Real video comes from Vertex Veo (#14) on the GCP trial. OpenAI stays in the project as a **judge** (#7), because judging only needs image input, which OpenAI still offers.

Reconsider this if OpenAI ships a new image-to-video API. Check the deprecations page and the API docs first; a third-party aggregator reselling old Sora access doesn't count.

Sources: [OpenAI API deprecations](https://developers.openai.com/api/docs/deprecations), [Sora 2 is here](https://openai.com/index/sora-2/).

## Prior requests

- Raised in chat on 2026-11-05 during triage of #7 ("allow the use of openai to generate videos not just gemini"). No issue was opened.
