<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Agent\RewriteInstructions;
use Tests\TestCase;

class RewriteInstructionsTest extends TestCase
{
    private const REASON = 'Left hand has six fingers.';

    public function test_first_rewrite_asks_to_simplify_the_failure(): void
    {
        $instruction = RewriteInstructions::for(self::REASON, 'Shot 1: neon alley', []);

        $this->assertSame(RewriteInstructions::simplify(self::REASON), $instruction);
    }

    public function test_asks_for_a_different_fix_when_the_scene_already_holds_the_simplify_fix(): void
    {
        $scene = 'Shot 1: neon alley '.RewriteInstructions::simplify(self::REASON);

        $instruction = RewriteInstructions::for(self::REASON, $scene, []);

        $this->assertSame(RewriteInstructions::different(self::REASON), $instruction);
    }

    public function test_asks_for_a_different_fix_when_a_previous_attempt_tried_the_simplify_fix(): void
    {
        $previousAttempts = [
            ['prompt' => 'Shot 1: neon alley '.RewriteInstructions::simplify(self::REASON), 'reason' => self::REASON],
        ];

        $instruction = RewriteInstructions::for(self::REASON, 'Shot 1: neon alley', $previousAttempts);

        $this->assertSame(RewriteInstructions::different(self::REASON), $instruction);
    }
}
