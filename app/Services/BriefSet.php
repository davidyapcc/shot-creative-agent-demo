<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class BriefSet
{
    public const CATEGORIES = [
        'people',
        'hands',
        'vehicles',
        'text on signs',
        'multi-shot continuity',
    ];

    /**
     * @return list<array{id: string, category: string, prompt: string}>
     */
    public static function load(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("Brief set is missing: {$path}");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Brief set is not valid JSON: {$path}");
        }

        $briefs = [];
        $seen = [];

        foreach ($decoded as $entry) {
            $brief = self::brief($entry);

            if (isset($seen[$brief['id']])) {
                throw new RuntimeException("Brief {$brief['id']} is duplicated.");
            }

            $seen[$brief['id']] = true;
            $briefs[] = $brief;
        }

        return $briefs;
    }

    /**
     * @return array{id: string, category: string, prompt: string}
     */
    private static function brief(mixed $entry): array
    {
        if (! is_array($entry)) {
            throw new RuntimeException('Brief set has an entry that is not a brief.');
        }

        $id = $entry['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Brief set has a brief with no id.');
        }

        $category = $entry['category'] ?? null;
        $prompt = $entry['prompt'] ?? null;

        if (! is_string($category) || ! in_array($category, self::CATEGORIES, true)) {
            $shown = is_string($category) ? $category : get_debug_type($category);

            throw new RuntimeException("Brief {$id} has an unknown category [{$shown}].");
        }

        if (! is_string($prompt) || trim($prompt) === '') {
            throw new RuntimeException("Brief {$id} is missing a prompt.");
        }

        return [
            'id' => $id,
            'category' => $category,
            'prompt' => trim($prompt),
        ];
    }
}
