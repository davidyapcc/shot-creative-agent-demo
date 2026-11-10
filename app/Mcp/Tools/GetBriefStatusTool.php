<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Brief;
use App\Models\Shot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_brief_status')]
#[Description('Read a brief\'s state, final video path, and each shot\'s state, attempts, and latest verdict.')]
#[IsReadOnly]
class GetBriefStatusTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'brief_id' => ['required', 'integer'],
        ]);

        $brief = Brief::query()->with(['shots.judgements'])->find($validated['brief_id']);

        if ($brief === null) {
            return Response::error('Brief ['.$validated['brief_id'].'] was not found.');
        }

        return Response::structured([
            'state' => $brief->state->value,
            'final_video_path' => $brief->final_video_path,
            'shots' => $brief->shots->map(fn (Shot $shot): array => [
                'sequence' => $shot->sequence,
                'state' => $shot->state->value,
                'attempts' => $shot->attempts,
                'verdict' => $shot->judgements->last()?->verdict,
            ])->values()->all(),
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
            'brief_id' => $schema->integer()
                ->description('Id returned by submit_brief.')
                ->required(),
        ];
    }
}
