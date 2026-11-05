<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\AgentInterface;
use App\Exceptions\ProviderRateLimitedException;
use App\Services\Agent\MockAgent;
use App\Services\Agent\PrismAgent;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\TestCase;

class PrismAgentTest extends TestCase
{
    private const VALID_PLAN = [
        'style_anchor' => 'Cinematic neon rain.',
        'shots' => [
            ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
            ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
        ],
    ];

    public function test_mock_agent_remains_the_default(): void
    {
        config(['creative.agent.provider' => 'mock']);

        $this->assertInstanceOf(MockAgent::class, app(AgentInterface::class));
    }

    public function test_gemini_agent_is_selected_by_provider_and_model(): void
    {
        config([
            'creative.agent.provider' => 'gemini',
            'creative.agent.model' => 'gemini-3.8-flash',
            'services.gemini.key' => 'test-gemini-key',
        ]);

        $this->assertInstanceOf(PrismAgent::class, app(AgentInterface::class));
    }

    public function test_gemini_agent_requires_an_api_key(): void
    {
        config([
            'creative.agent.provider' => 'gemini',
            'creative.agent.model' => 'gemini-3.8-flash',
            'services.gemini.key' => '  ',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY is required when AGENT_PROVIDER=gemini.');

        app(AgentInterface::class);
    }

    public function test_openai_agent_is_selected_by_provider(): void
    {
        config([
            'creative.agent.provider' => 'openai',
            'creative.agent.model' => 'gpt-5.4-mini',
            'services.openai.key' => 'test-openai-key',
        ]);

        $this->assertInstanceOf(PrismAgent::class, app(AgentInterface::class));
    }

    public function test_openai_agent_requires_an_api_key(): void
    {
        config([
            'creative.agent.provider' => 'openai',
            'creative.agent.model' => 'gpt-5.4-mini',
            'services.openai.key' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY is required when AGENT_PROVIDER=openai.');

        app(AgentInterface::class);
    }

    public function test_cloudflare_llama_agent_is_selected_by_provider(): void
    {
        config([
            'creative.agent.provider' => 'cloudflare',
            'creative.agent.model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'services.cloudflare.key' => 'test-cloudflare-token',
            'services.cloudflare.account_id' => 'account-id',
        ]);

        $this->assertInstanceOf(PrismAgent::class, app(AgentInterface::class));
    }

    public function test_cloudflare_agent_requires_a_token(): void
    {
        config([
            'creative.agent.provider' => 'cloudflare',
            'creative.agent.model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'services.cloudflare.key' => '',
            'services.cloudflare.account_id' => 'account-id',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CLOUDFLARE_API_TOKEN is required when AGENT_PROVIDER=cloudflare.');

        app(AgentInterface::class);
    }

    public function test_cloudflare_agent_requires_a_token_and_account(): void
    {
        config([
            'creative.agent.provider' => 'cloudflare',
            'creative.agent.model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'services.cloudflare.key' => 'test-cloudflare-token',
            'services.cloudflare.account_id' => ' ',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CLOUDFLARE_ACCOUNT_ID is required when AGENT_PROVIDER=cloudflare.');

        app(AgentInterface::class);
    }

    public function test_plan_shots_returns_two_or_three_validated_shots(): void
    {
        $fake = Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => 'Cinematic neon rain.',
                'shots' => [
                    ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
                    ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
                ],
            ]),
        ]);

        $plan = $this->agent()->planShots('15s product teaser, rainy Tokyo, neon');

        $this->assertSame([
            ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
            ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
        ], $plan->shots);
        $this->assertSame('Cinematic neon rain.', $plan->styleAnchor);

        $fake->assertRequest(function (array $requests): void {
            $this->assertCount(1, $requests);
            $this->assertSame('gemini', $requests[0]->provider());
            $this->assertSame('gemini-3.8-flash', $requests[0]->model());
            $this->assertStringContainsString('2 or 3', (string) $requests[0]->prompt());
            $this->assertStringContainsString('style anchor', (string) $requests[0]->prompt());
            $this->assertStringContainsString('15s product teaser, rainy Tokyo, neon', (string) $requests[0]->prompt());
        });
    }

    public function test_plan_shots_accepts_three_shots(): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => 'Cinematic neon rain.',
                'shots' => [
                    ['sequence' => 3, 'prompt' => 'Detail of the cup.'],
                    ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
                    ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
                ],
            ]),
        ]);

        $plan = $this->agent()->planShots('rainy Tokyo');

        $this->assertSame([
            ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
            ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
            ['sequence' => 3, 'prompt' => 'Detail of the cup.'],
        ], $plan->shots);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function defaultModels(): array
    {
        return [
            'gemini' => ['gemini', 'gemini-3.8-flash'],
            'openai' => ['openai', 'gpt-5.4-mini'],
            'cloudflare' => ['cloudflare', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
        ];
    }

    #[DataProvider('defaultModels')]
    public function test_an_unset_agent_model_uses_the_providers_own_default(string $provider, string $model): void
    {
        config([
            'creative.agent.provider' => $provider,
            'creative.agent.model' => null,
            "services.{$provider}.key" => 'test-key',
            'services.cloudflare.account_id' => 'account-id',
        ]);
        $fake = Prism::fake([StructuredResponseFake::make()->withStructured(self::VALID_PLAN)]);

        app(AgentInterface::class)->planShots('rainy Tokyo');

        $fake->assertRequest(fn (array $requests) => $this->assertSame($model, $requests[0]->model()));
    }

    /**
     * @return array<string, array{list<int>}>
     */
    public static function brokenSequences(): array
    {
        return [
            'duplicate' => [[1, 1]],
            'gap' => [[1, 5]],
            'not starting at one' => [[2, 3]],
        ];
    }

    /**
     * @param  list<int>  $sequences
     */
    #[DataProvider('brokenSequences')]
    public function test_plan_shots_rejects_sequences_that_are_not_one_to_n(array $sequences): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => 'Cinematic neon rain.',
                'shots' => array_map(
                    fn (int $sequence): array => ['sequence' => $sequence, 'prompt' => "Shot {$sequence}."],
                    $sequences,
                ),
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shot sequences must run 1 to n with no gaps or repeats.');

        $this->agent()->planShots('rainy Tokyo');
    }

    public function test_plan_shots_rejects_a_plan_that_is_not_two_or_three_shots(): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => 'Cinematic neon rain.',
                'shots' => [
                    ['sequence' => 1, 'prompt' => 'Only one shot.'],
                ],
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent plan must contain 2 or 3 shots.');

        $this->agent()->planShots('rainy Tokyo');
    }

    public function test_plan_shots_rejects_a_shot_without_a_prompt(): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => 'Cinematic neon rain.',
                'shots' => [
                    ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
                    ['sequence' => 2, 'prompt' => '   '],
                ],
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Each shot needs a sequence and a prompt.');

        $this->agent()->planShots('rainy Tokyo');
    }

    public function test_plan_shots_rejects_a_plan_without_a_style_anchor(): void
    {
        Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'style_anchor' => '  ',
                'shots' => [
                    ['sequence' => 1, 'prompt' => 'Wide shot of rainy Tokyo.'],
                    ['sequence' => 2, 'prompt' => 'Close shot of the neon sign.'],
                ],
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent plan needs a style anchor.');

        $this->agent()->planShots('rainy Tokyo');
    }

    public function test_rewrite_sends_the_same_inputs_and_instructions_as_the_mock_path(): void
    {
        $fake = Prism::fake([
            StructuredResponseFake::make()->withStructured([
                'prompt' => 'Shot 1: neon alley. Fewer fingers, same rainy street.',
            ]),
        ]);

        $rewritten = $this->agent()->rewritePrompt([
            'brief' => '15s product teaser, rainy Tokyo, neon',
            'original_prompt' => 'Shot 1: neon alley',
            'failed_scores' => ['anatomy' => 2, 'prompt_adherence' => 4],
            'reason' => 'Left hand has six fingers.',
            'previous_attempts' => [
                ['prompt' => 'Shot 1: neon alley, simplified hand', 'reason' => 'Still six fingers.'],
            ],
        ]);

        $this->assertSame('Shot 1: neon alley. Fewer fingers, same rainy street.', $rewritten);

        $fake->assertRequest(function (array $requests): void {
            $prompt = (string) $requests[0]->prompt();

            $this->assertStringContainsString(
                'Simplify only this failure: Left hand has six fingers. Keep the same scene.',
                $prompt,
            );
            $this->assertStringContainsString(
                'Use a different simplification than before for: Left hand has six fingers. Keep the same scene.',
                $prompt,
            );
            $this->assertStringContainsString('15s product teaser, rainy Tokyo, neon', $prompt);
            $this->assertStringContainsString('Shot 1: neon alley', $prompt);
            $this->assertStringContainsString('Left hand has six fingers.', $prompt);
            $this->assertStringContainsString('anatomy', $prompt);
            $this->assertStringContainsString('Shot 1: neon alley, simplified hand', $prompt);
            $this->assertStringContainsString('Still six fingers.', $prompt);
        });
    }

    public function test_cloudflare_llama_uses_the_workers_ai_endpoint(): void
    {
        config([
            'creative.agent.provider' => 'cloudflare',
            'creative.agent.model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'services.cloudflare.key' => 'test-key',
            'services.cloudflare.account_id' => 'account id',
        ]);

        $fake = Prism::fake([StructuredResponseFake::make()->withStructured(self::VALID_PLAN)]);

        $plan = app(AgentInterface::class)->planShots('rainy Tokyo');

        $this->assertSame('Cinematic neon rain.', $plan->styleAnchor);
        $fake->assertProviderConfig([
            'api_key' => 'test-key',
            'url' => 'https://api.cloudflare.com/client/v4/accounts/account%20id/ai/v1',
        ]);
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('openai', $requests[0]->provider());
            $this->assertSame('@cf/meta/llama-3.3-70b-instruct-fp8-fast', $requests[0]->model());
        });
    }

    public function test_provider_rate_limit_raises_provider_rate_limited_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'rate limit']], 429),
        ]);

        $this->expectException(ProviderRateLimitedException::class);
        $this->expectExceptionMessage('Gemini agent returned HTTP 429.');

        $this->agent()->planShots('rainy Tokyo');
    }

    private function agent(
        string $provider = 'gemini',
        string $model = 'gemini-3.8-flash',
        ?string $url = null,
    ): PrismAgent {
        return new PrismAgent($provider, $model, 'test-key', $url);
    }
}
