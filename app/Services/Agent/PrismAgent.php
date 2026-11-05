<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Contracts\AgentInterface;
use App\DTOs\ShotPlan;
use App\Exceptions\ProviderRateLimitedException;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class PrismAgent implements AgentInterface
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly ?string $url = null,
    ) {}

    public function planShots(string $prompt): ShotPlan
    {
        return ShotPlan::fromStructured($this->structured(
            $this->shotPlanSchema(),
            "Plan 2 or 3 shots for this brief, and write one style anchor: the consistent look every keyframe will share.\n\nBrief: {$prompt}",
        ));
    }

    public function rewritePrompt(array $context): string
    {
        $structured = $this->structured(
            new ObjectSchema(
                name: 'rewritten_prompt',
                description: 'A rewritten shot prompt',
                properties: [
                    new StringSchema('prompt', 'The rewritten shot prompt'),
                ],
                requiredFields: ['prompt'],
            ),
            $this->rewriteInstructions($context),
        );

        $prompt = $structured['prompt'] ?? null;

        if (! is_string($prompt) || trim($prompt) === '') {
            throw new InvalidArgumentException('Agent rewrite must be a non-empty prompt.');
        }

        return trim($prompt);
    }

    /**
     * @param  array{
     *   brief: string,
     *   original_prompt: string,
     *   failed_scores: array<string, int>,
     *   reason: string|null,
     *   previous_attempts: list<array{prompt: string, reason: string|null}>
     * }  $context
     */
    private function rewriteInstructions(array $context): string
    {
        $scores = json_encode($context['failed_scores'], JSON_THROW_ON_ERROR);

        $reason = $context['reason'] ?? 'the failing element';

        return implode("\n", [
            'Rewrite the failed shot prompt.',
            RewriteInstructions::simplify($reason),
            'If that fix already appears in the scene or a previous attempt, '
                .RewriteInstructions::different($reason),
            'brief: '.$context['brief'],
            'original_prompt: '.$context['original_prompt'],
            'failed_scores: '.$scores,
            'reason: '.($context['reason'] ?? ''),
            'previous_attempts:',
            $this->previousAttempts($context['previous_attempts']),
        ]);
    }

    /**
     * @param  list<array{prompt?: string, reason?: string|null}>  $attempts
     */
    private function previousAttempts(array $attempts): string
    {
        if ($attempts === []) {
            return '(none)';
        }

        $lines = [];

        foreach ($attempts as $attempt) {
            $lines[] = '- prompt: '.($attempt['prompt'] ?? '').' | reason: '.($attempt['reason'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function structured(ObjectSchema $schema, string $prompt): array
    {
        try {
            $response = Prism::structured()
                ->using($this->prismProvider(), $this->model, $this->providerConfig())
                ->withSchema($schema)
                ->withPrompt($prompt)
                ->asStructured();
        } catch (PrismRateLimitedException $exception) {
            throw new ProviderRateLimitedException(
                ucfirst($this->provider).' agent returned HTTP 429.',
                previous: $exception,
            );
        }

        if (! is_array($response->structured)) {
            throw new InvalidArgumentException('Agent returned an invalid structured response.');
        }

        return $response->structured;
    }

    private function prismProvider(): Provider
    {
        return match ($this->provider) {
            'gemini' => Provider::Gemini,
            'openai', 'cloudflare' => Provider::OpenAI,
            default => throw new InvalidArgumentException("Unsupported agent provider [{$this->provider}]."),
        };
    }

    /**
     * @return array<string, string>
     */
    private function providerConfig(): array
    {
        $config = ['api_key' => $this->apiKey];

        if ($this->url !== null) {
            $config['url'] = $this->url;
        }

        return $config;
    }

    private function shotPlanSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'shot_plan',
            description: 'Two or three shots planned from a creative brief, with one shared style anchor',
            properties: [
                new StringSchema('style_anchor', 'Consistent visual style applied to every keyframe'),
                new ArraySchema(
                    name: 'shots',
                    description: 'Between 2 and 3 shots',
                    items: new ObjectSchema(
                        name: 'shot',
                        description: 'One planned shot',
                        properties: [
                            new NumberSchema('sequence', '1-based position of the shot', minimum: 1),
                            new StringSchema('prompt', 'The shot prompt'),
                        ],
                        requiredFields: ['sequence', 'prompt'],
                    ),
                    minItems: 2,
                    maxItems: 3,
                ),
            ],
            requiredFields: ['style_anchor', 'shots'],
        );
    }
}
