<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Agent\MockAgent;
use Tests\TestCase;

class MockAgentTest extends TestCase
{
  public function test_mock_agent_plans_shots_deterministically(): void
  {
    $agent = new MockAgent();
    $prompt = '15s product teaser, rainy Tokyo, neon';

    $shots1 = $agent->planShots($prompt);
    $shots2 = $agent->planShots($prompt);

    $this->assertCount(3, $shots1);
    $this->assertSame($shots1, $shots2);

    $this->assertSame(1, $shots1[0]['sequence']);
    $this->assertSame(2, $shots1[1]['sequence']);
    $this->assertSame(3, $shots1[2]['sequence']);

    $this->assertStringContainsString('Tokyo', $shots1[0]['prompt']);
    $this->assertNotEmpty($agent->generateStyleAnchor($prompt));
  }
}
