<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BriefState;
use App\Jobs\PlanBriefJob;
use App\Models\Brief;

class BriefSubmitter
{
    public const PROMPT_MIN_LENGTH = 3;

    public const PROMPT_MAX_LENGTH = 1000;

    /**
     * Prompt rules shared by POST /briefs and the submit_brief tool.
     *
     * @var list<string>
     */
    public const PROMPT_RULES = [
        'required',
        'string',
        'min:'.self::PROMPT_MIN_LENGTH,
        'max:'.self::PROMPT_MAX_LENGTH,
    ];

    public function submit(string $prompt): Brief
    {
        $brief = Brief::create([
            'prompt' => $prompt,
            'state' => BriefState::Pending,
        ]);

        PlanBriefJob::dispatch($brief->id);

        return $brief;
    }
}
