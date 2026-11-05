<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Contracts\AgentInterface;
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

    public function planShots(string $prompt): array
    {
        return $this->validatedShots($this->structured(
            $this->shotPlanSchema(),
            "Plan 2 or 3 shots for this brief.\n\nBrief: {$prompt}",
        ));
    }

    public function generateStyleAnchor(string $prompt): string
    {
        $structured = $this->structured(
            new ObjectSchema(
                name: 'style_anchor',
                description: 'A style anchor applied to every keyframe',
                properties: [
                    new StringSchema('style_anchor', 'Consistent visual style for the keyframes'),
                ],
                requiredFields: ['style_anchor'],
            ),
            "Write a style anchor for the keyframes of this brief. Keep one consistent look.\n\nBrief: {$prompt}",
        );

        $anchor = $structured['style_anchor'] ?? null;

        if (! is_string($anchor) || trim($anchor) === '') {
            throw new InvalidArgumentException('Agent style anchor must be a non-empty string.');
        }

        return trim($anchor);
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
            description: 'Two or three shots planned from a creative brief',
            properties: [
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
            requiredFields: ['shots'],
        );
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<int, array{sequence: int, prompt: string}>
     */
    private function validatedShots(array $structured): array
    {
        $shots = $structured['shots'] ?? null;

        if (! is_array($shots) || ! array_is_list($shots) || count($shots) < 2 || count($shots) > 3) {
            throw new InvalidArgumentException('Agent plan must contain 2 or 3 shots.');
        }

        $planned = [];

        foreach ($shots as $shot) {
            $planned[] = $this->validatedShot($shot);
        }

        usort($planned, fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);

        if (array_column($planned, 'sequence') !== range(1, count($planned))) {
            throw new InvalidArgumentException('Shot sequences must run 1 to n with no gaps or repeats.');
        }

        return $planned;
    }

    /**
     * @return array{sequence: int, prompt: string}
     */
    private function validatedShot(mixed $shot): array
    {
        if (! is_array($shot)) {
            throw new InvalidArgumentException('Each shot needs a sequence and a prompt.');
        }

        $sequence = $shot['sequence'] ?? null;
        $prompt = $shot['prompt'] ?? null;

        if (! is_int($sequence) || $sequence < 1 || ! is_string($prompt) || trim($prompt) === '') {
            throw new InvalidArgumentException('Each shot needs a sequence and a prompt.');
        }

        return [
            'sequence' => $sequence,
            'prompt' => trim($prompt),
        ];
    }
}
