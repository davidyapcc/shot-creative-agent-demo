<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Builds one entry of `EvalRun::results` the way `eval:run` stores it.
 */
trait BuildsStoredClipResults
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $humanTags
     * @return array<string, mixed>
     */
    private function clip(string $id, string $verdict, array $tags, string $humanVerdict, array $humanTags): array
    {
        return [
            'id' => $id,
            'verdict' => $verdict,
            'tags' => $tags,
            'human' => [
                'verdict' => $humanVerdict,
                'tags' => $humanTags,
            ],
        ];
    }
}
