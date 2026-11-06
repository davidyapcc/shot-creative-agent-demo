<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BriefSet;
use RuntimeException;
use Tests\TestCase;

class BriefSetTest extends TestCase
{
    public function test_brief_set_covers_the_five_hard_subjects(): void
    {
        $briefs = BriefSet::load(base_path('evals/briefs.json'));

        $this->assertCount(15, $briefs);

        foreach (BriefSet::CATEGORIES as $category) {
            $inCategory = array_filter(
                $briefs,
                fn (array $brief): bool => $brief['category'] === $category,
            );

            $this->assertCount(3, $inCategory, $category);
        }

        foreach ($briefs as $brief) {
            $this->assertNotSame('', $brief['prompt']);
        }
    }

    public function test_unknown_category_names_the_brief(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'briefs');
        file_put_contents($path, json_encode([[
            'id' => 'person-crossing',
            'category' => 'animals',
            'prompt' => 'A person crosses a street.',
        ]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('person-crossing');

        try {
            BriefSet::load($path);
        } finally {
            unlink($path);
        }
    }
}
