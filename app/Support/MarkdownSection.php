<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Replaces one `## ` section of a Markdown document and leaves every other byte alone.
 *
 * A section runs from its exact heading line to the next level-1 or level-2
 * heading. Lines inside a closed code fence never start or end a section. A
 * fence that is never closed counts as plain text, so it cannot swallow the
 * sections after it.
 */
final class MarkdownSection
{
    private const INDENT = ' {0,3}';

    public static function replace(string $document, string $heading, string $section): string
    {
        $eol = str_contains($document, "\r\n") ? "\r\n" : "\n";
        $section = str_replace("\n", $eol, str_replace("\r\n", "\n", $section));

        if ($document === '') {
            return $section;
        }

        $lines = explode("\n", $document);
        $fenced = self::fencedLines($lines);
        $start = self::findLine($lines, $fenced, 0, fn (string $line): bool => $line === $heading);

        if ($start === null) {
            return $document.self::separator($document, $eol).$section;
        }

        $next = self::findLine($lines, $fenced, $start + 1, fn (string $line): bool => self::isSectionBreak($line));
        $prefix = implode("\n", array_slice($lines, 0, $start));
        $prefix = $start === 0 ? '' : $prefix."\n";

        if ($next === null) {
            return $prefix.$section;
        }

        $rest = implode("\n", array_slice($lines, $next));

        return $prefix.rtrim($section, "\r\n").$eol.$eol.$rest;
    }

    private static function separator(string $document, string $eol): string
    {
        return match (true) {
            str_ends_with($document, $eol.$eol) => '',
            str_ends_with($document, $eol) => $eol,
            default => $eol.$eol,
        };
    }

    private static function isSectionBreak(string $line): bool
    {
        return preg_match('/^'.self::INDENT.'#{1,2}(?:[ \t]|$)/', $line) === 1;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<int, true>  $fenced
     * @param  Closure(string): bool  $isMatch
     */
    private static function findLine(array $lines, array $fenced, int $from, Closure $isMatch): ?int
    {
        $count = count($lines);

        for ($index = $from; $index < $count; $index++) {
            if (! isset($fenced[$index]) && $isMatch(rtrim($lines[$index], "\r"))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Indexes of the lines inside a closed code fence, its fence lines included.
     *
     * @param  list<string>  $lines
     * @return array<int, true>
     */
    private static function fencedLines(array $lines): array
    {
        $fenced = [];
        $opening = null;
        $marker = '';

        foreach ($lines as $index => $line) {
            $line = rtrim($line, "\r");

            if ($opening === null) {
                $marker = self::openingFence($line) ?? '';
                $opening = $marker === '' ? null : $index;

                continue;
            }

            if (self::closesFence($line, $marker)) {
                $fenced += array_fill_keys(range($opening, $index), true);
                $opening = null;
            }
        }

        return $fenced;
    }

    private static function openingFence(string $line): ?string
    {
        if (preg_match('/^'.self::INDENT.'(`{3,})[^`]*$/', $line, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^'.self::INDENT.'(~{3,})/', $line, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private static function closesFence(string $line, string $marker): bool
    {
        if (preg_match('/^'.self::INDENT.'(`{3,}|~{3,})[ \t]*$/', $line, $matches) !== 1) {
            return false;
        }

        return $matches[1][0] === $marker[0] && strlen($matches[1]) >= strlen($marker);
    }
}
