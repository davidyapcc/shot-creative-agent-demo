<?php

declare(strict_types=1);

namespace Tests\Unit;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class PaidCallOwnershipTest extends TestCase
{
    /**
     * The eval side charges its own budget (ADR-0001), so it stays outside PaidCall.
     */
    private const ALLOWED = ['Budget.php', 'PaidCall.php', 'EvalBudget.php', 'MeteredClipJudge.php'];

    private const BUDGET_CALL = '/->(chargeShotStep|chargeOrDegrade|refundShotStep|refund)\(/';

    public function test_only_the_paid_call_module_charges_or_refunds_the_brief_budget(): void
    {
        // Arrange
        $files = Finder::create()->files()->in(app_path())->name('*.php')->notName(self::ALLOWED);

        // Act
        $offenders = [];
        foreach ($files as $file) {
            if (preg_match(self::BUDGET_CALL, $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        // Assert
        $this->assertSame([], $offenders, 'Paid calls go through App\Services\PaidCall.');
    }
}
