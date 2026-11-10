<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\BriefSubmitter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('submit_brief')]
#[Description('Create a brief and enqueue planning. Returns immediately and does not wait for a render.')]
class SubmitBriefTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, BriefSubmitter $submitter): ResponseFactory
    {
        $validated = $request->validate([
            'prompt' => BriefSubmitter::PROMPT_RULES,
        ]);

        $brief = $submitter->submit($validated['prompt']);

        return Response::structured([
            'id' => $brief->id,
            'state' => $brief->state->value,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('Creative brief, from '.BriefSubmitter::PROMPT_MIN_LENGTH.' to '.BriefSubmitter::PROMPT_MAX_LENGTH.' characters.')
                ->min(BriefSubmitter::PROMPT_MIN_LENGTH)
                ->max(BriefSubmitter::PROMPT_MAX_LENGTH)
                ->required(),
        ];
    }
}
