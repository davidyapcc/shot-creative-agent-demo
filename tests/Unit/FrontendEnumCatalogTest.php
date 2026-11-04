<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FrontendEnumCatalog;
use Tests\TestCase;

class FrontendEnumCatalogTest extends TestCase
{
  public function test_pipeline_states_json_matches_php_enums(): void
  {
    $path = base_path('resources/js/pipeline-states.json');

    $this->assertSame(FrontendEnumCatalog::toJson(), file_get_contents($path));
  }
}
