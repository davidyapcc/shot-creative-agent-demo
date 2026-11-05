<?php

declare(strict_types=1);

namespace App\DTOs;

class AudioResult
{
    public function __construct(
        public readonly string $voiceover,
        public readonly string $soundEffect,
    ) {}
}
