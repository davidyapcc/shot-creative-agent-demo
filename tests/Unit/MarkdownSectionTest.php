<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MarkdownSection;
use PHPUnit\Framework\TestCase;

class MarkdownSectionTest extends TestCase
{
    private const HEADING = '## Judge agreement';

    private const SECTION = "## Judge agreement\n\n- Agreement rate: 1.000\n";

    public function test_a_missing_document_body_becomes_the_section(): void
    {
        $this->assertSame(self::SECTION, MarkdownSection::replace('', self::HEADING, self::SECTION));
    }

    public function test_a_section_in_the_middle_is_replaced_and_every_other_byte_kept(): void
    {
        $prefix = "# Eval report\n\n## Metrics\n\n- Precision: 1.000\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";

        $after = MarkdownSection::replace(
            $prefix."## Judge agreement\n\n- Agreement rate: 0.000\n\n".$suffix,
            self::HEADING,
            self::SECTION,
        );

        $this->assertSame($prefix.self::SECTION."\n".$suffix, $after);
    }

    public function test_a_section_at_the_end_is_replaced_and_every_other_byte_kept(): void
    {
        $prefix = "# Eval report\n\n## Metrics\n\n- Precision: 1.000\n\n";

        $after = MarkdownSection::replace(
            $prefix."## Judge agreement\n\n- Agreement rate: 0.000\n",
            self::HEADING,
            self::SECTION,
        );

        $this->assertSame($prefix.self::SECTION, $after);
    }

    public function test_a_document_without_the_section_gains_it_after_a_blank_line(): void
    {
        $this->assertSame(
            "# Eval report\n\n".self::SECTION,
            MarkdownSection::replace("# Eval report\n", self::HEADING, self::SECTION),
        );
        $this->assertSame(
            "SENTINEL\n\n".self::SECTION,
            MarkdownSection::replace('SENTINEL', self::HEADING, self::SECTION),
        );
    }

    public function test_a_similar_heading_is_not_the_section(): void
    {
        $prefix = "# Eval report\n\n## Judge agreements (old)\n\n- Agreement rate: 0.000\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";

        $after = MarkdownSection::replace(
            $prefix."## Judge agreement\n\n- Agreement rate: 0.100\n\n".$suffix,
            self::HEADING,
            self::SECTION,
        );

        $this->assertSame($prefix.self::SECTION."\n".$suffix, $after);
    }

    public function test_a_heading_inside_a_closed_fence_does_not_end_the_section(): void
    {
        $prefix = "# Eval report\n\n";
        $suffix = "## Disagreements\n\n- warp-car: human fail (object_warp)\n";

        $after = MarkdownSection::replace(
            $prefix."## Judge agreement\n\n```\n## Inside the fence\n```\n\n".$suffix,
            self::HEADING,
            self::SECTION,
        );

        $this->assertSame($prefix.self::SECTION."\n".$suffix, $after);
    }

    public function test_an_unclosed_fence_inside_the_section_keeps_the_sections_after_it(): void
    {
        $prefix = "# Eval report\n\n";
        $suffix = "## Disagreements\n\n- keep me\n";

        $after = MarkdownSection::replace(
            $prefix."## Judge agreement\n\n```\nstray\n\n".$suffix,
            self::HEADING,
            self::SECTION,
        );

        $this->assertSame($prefix.self::SECTION."\n".$suffix, $after);
    }

    public function test_the_heading_inside_an_indented_fence_is_not_the_section(): void
    {
        $prefix = "# Report\n\n   ```\n## Judge agreement\n   ```\n\n";
        $suffix = "## Tail\n";

        $after = MarkdownSection::replace(
            $prefix."## Judge agreement\n\nold\n\n".$suffix,
            self::HEADING,
            self::SECTION,
        );

        $this->assertSame($prefix.self::SECTION."\n".$suffix, $after);
    }

    public function test_a_windows_document_keeps_its_line_endings_in_the_new_section(): void
    {
        $prefix = "# Eval report\r\n\r\n";
        $suffix = "## Disagreements\r\n\r\n- warp-car\r\n";

        $after = MarkdownSection::replace(
            $prefix."## Judge agreement\r\n\r\nold\r\n\r\n".$suffix,
            self::HEADING,
            self::SECTION,
        );

        $this->assertSame(
            $prefix."## Judge agreement\r\n\r\n- Agreement rate: 1.000\r\n\r\n".$suffix,
            $after,
        );
    }

    public function test_a_windows_document_without_the_section_gains_it_with_windows_line_endings(): void
    {
        $this->assertSame(
            "# Eval report\r\n\r\n## Judge agreement\r\n\r\n- Agreement rate: 1.000\r\n",
            MarkdownSection::replace("# Eval report\r\n", self::HEADING, self::SECTION),
        );
    }
}
