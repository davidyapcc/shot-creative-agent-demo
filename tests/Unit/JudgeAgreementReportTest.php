<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JudgeAgreementReport;
use RuntimeException;
use Tests\TestCase;

class JudgeAgreementReportTest extends TestCase
{
    private const SECTION = "## Judge agreement\n\n- Agreement rate: 1.000\n";

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/judge-agreement-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory.'/evals/REPORT.md', $this->directory.'/blocker'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ([$this->directory.'/evals', $this->directory] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_a_missing_report_is_created_with_only_the_section(): void
    {
        $path = $this->directory.'/evals/REPORT.md';

        (new JudgeAgreementReport)->write($path, self::SECTION);

        $this->assertSame(self::SECTION, file_get_contents($path));
    }

    public function test_a_directory_that_cannot_be_created_fails_loudly(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory.'/blocker', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not create');

        (new JudgeAgreementReport)->write($this->directory.'/blocker/REPORT.md', self::SECTION);
    }

    public function test_a_report_that_cannot_be_written_fails_loudly(): void
    {
        mkdir($this->directory.'/evals', 0777, true);
        mkdir($this->directory.'/evals/REPORT.md');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Could not write');

            (new JudgeAgreementReport)->write($this->directory.'/evals/REPORT.md', self::SECTION);
        } finally {
            rmdir($this->directory.'/evals/REPORT.md');
        }
    }
}
