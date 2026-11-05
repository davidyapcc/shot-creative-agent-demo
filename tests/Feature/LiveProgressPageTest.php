<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class LiveProgressPageTest extends TestCase
{
    public function test_welcome_page_renders_live_progress_ui_and_reverb_config(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<div id="app"></div>', false);
        $response->assertSee('window.__CONFIG__', false);
        $response->assertSee(config('broadcasting.connections.reverb.key', 'shot-creative-key'));
        $response->assertSee('Shot Creative Agent Demo', false);
        $response->assertSee('app-', false);
    }
}
