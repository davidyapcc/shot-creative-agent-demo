<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

class JsonFile
{
    /**
     * Read and decode a JSON file that must hold an object or a list.
     *
     * @return array<mixed>
     */
    public static function decode(string $path, string $label): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("{$label} is missing: {$path}");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$label} is not valid JSON: {$path}");
        }

        return $decoded;
    }
}
