<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AudioProviderInterface;
use App\Contracts\ImageProviderInterface;
use App\Contracts\JudgeInterface;
use App\Contracts\VideoProviderInterface;
use App\DTOs\AudioResult;
use App\DTOs\JudgementResult;
use App\DTOs\VideoRenderResult;
use App\Enums\BriefState;
use App\Enums\JudgementStage;
use App\Enums\JudgementVerdict;
use App\Enums\ShotState;
use App\Jobs\PlanBriefJob;
use App\Mcp\Servers\BriefServer;
use App\Mcp\Tools\GetBriefStatusTool;
use App\Mcp\Tools\SubmitBriefTool;
use App\Models\Brief;
use App\Models\Judgement;
use App\Models\Shot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Registrar;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BriefMcpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ImageProviderInterface::class, fn () => new class implements ImageProviderInterface
        {
            public function generateImage(string $prompt, ?string $styleAnchor = null): string
            {
                throw new RuntimeException('image provider must not be called');
            }
        });

        $this->app->bind(VideoProviderInterface::class, fn () => new class implements VideoProviderInterface
        {
            public function submit(string $prompt, string $keyframePath): string
            {
                throw new RuntimeException('video provider must not be called');
            }

            public function poll(string $renderId): VideoRenderResult
            {
                throw new RuntimeException('video provider must not be called');
            }
        });

        $this->app->bind(AudioProviderInterface::class, fn () => new class implements AudioProviderInterface
        {
            public function generate(string $prompt, ?string $styleAnchor = null): AudioResult
            {
                throw new RuntimeException('audio provider must not be called');
            }
        });

        $this->app->bind(JudgeInterface::class, fn () => new class implements JudgeInterface
        {
            public function judgeImage(string $imagePath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                throw new RuntimeException('judge must not be called');
            }

            public function judgeVideo(string $videoPath, string $prompt, ?string $styleAnchor = null): JudgementResult
            {
                throw new RuntimeException('judge must not be called');
            }
        });
    }

    public function test_the_brief_server_is_registered_for_stdio(): void
    {
        $this->assertSame(['briefs'], array_keys(app(Registrar::class)->servers()));
    }

    public function test_submit_brief_enqueues_a_pending_brief_and_returns_its_id(): void
    {
        Queue::fake();

        $response = BriefServer::tool(SubmitBriefTool::class, [
            'prompt' => '15s product teaser, rainy Tokyo, neon',
        ]);

        $brief = Brief::query()->sole();

        $response->assertOk()
            ->assertName('submit_brief')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('id', $brief->id)
                ->where('state', BriefState::Pending->value)
            );

        $this->assertSame('15s product teaser, rainy Tokyo, neon', $brief->prompt);
        $this->assertSame(BriefState::Pending, $brief->state);
        $this->assertSame(0, $brief->budget_used);
        $this->assertArrayNotHasKey('budget_ledger', $brief->metadata ?? []);

        Queue::assertPushed(PlanBriefJob::class, 1);
        Queue::assertPushed(PlanBriefJob::class, function (PlanBriefJob $job) use ($brief): bool {
            return $job->briefId === $brief->id;
        });
    }

    public function test_get_brief_status_returns_each_shot_in_sequence_with_its_latest_verdict(): void
    {
        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Done,
            'final_video_path' => 'renders/final.mp4',
        ]);

        Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 2,
            'prompt' => 'Shot 2: Close up of product on rainy asphalt',
            'state' => ShotState::GeneratingImage,
            'attempts' => 1,
        ]);

        $first = Shot::create([
            'brief_id' => $brief->id,
            'sequence' => 1,
            'prompt' => 'Shot 1: Neon sign reflecting on puddle',
            'state' => ShotState::Done,
            'attempts' => 2,
        ]);

        Judgement::create([
            'shot_id' => $first->id,
            'stage' => JudgementStage::Image->value,
            'scores' => ['prompt_adherence' => 2],
            'hard_fail_tags' => ['hands'],
            'reason' => 'Hands melt',
            'verdict' => JudgementVerdict::Fail->value,
        ]);

        Judgement::create([
            'shot_id' => $first->id,
            'stage' => JudgementStage::Video->value,
            'scores' => ['prompt_adherence' => 5],
            'hard_fail_tags' => [],
            'reason' => 'Clean clip',
            'verdict' => JudgementVerdict::Pass->value,
        ]);

        $response = BriefServer::tool(GetBriefStatusTool::class, [
            'brief_id' => $brief->id,
        ]);

        $response->assertOk()
            ->assertName('get_brief_status')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('state', BriefState::Done->value)
                ->where('final_video_path', 'renders/final.mp4')
                ->has('shots', 2)
                ->where('shots.0.sequence', 1)
                ->where('shots.0.state', ShotState::Done->value)
                ->where('shots.0.attempts', 2)
                ->where('shots.0.verdict', JudgementVerdict::Pass->value)
                ->where('shots.1.sequence', 2)
                ->where('shots.1.state', ShotState::GeneratingImage->value)
                ->where('shots.1.attempts', 1)
                ->where('shots.1.verdict', null)
            );

        $brief->refresh();
        $this->assertSame(0, $brief->budget_used);
        $this->assertArrayNotHasKey('budget_ledger', $brief->metadata ?? []);
    }

    public function test_get_brief_status_rejects_an_unknown_brief(): void
    {
        $response = BriefServer::tool(GetBriefStatusTool::class, [
            'brief_id' => 9999,
        ]);

        $response->assertHasErrors(['9999']);
        $this->assertSame(0, Brief::query()->count());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('invalidPrompts')]
    public function test_submit_brief_rejects_an_invalid_prompt(array $arguments): void
    {
        Queue::fake();

        $response = BriefServer::tool(SubmitBriefTool::class, $arguments);

        $response->assertHasErrors(['prompt']);
        $this->assertSame(0, Brief::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPrompts(): array
    {
        return [
            'missing' => [[]],
            'too short' => [['prompt' => 'ab']],
            'too long' => [['prompt' => str_repeat('a', 1001)]],
        ];
    }
}
