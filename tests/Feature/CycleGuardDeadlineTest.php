<?php

namespace Tests\Feature;

use Tests\TestCase;

covers(CycleGuardDeadlineTest::class);

/*
 * Issue #739 §6: the three cycle tests in UnitModelTest set a 3-second
 * statement_timeout, and a ~2ms query can blow that deadline under CPU load
 * (PHPStan and Pest running at the same time). The real cause of those flakes
 * was never parallel-order — the same seed went green again on a re-run.
 *
 * This test pins the guard's budget rather than the query's speed, so the
 * constant cannot be quietly lowered back into flaky territory. The guard
 * exists to stop a UNION ALL regression, and a UNION ALL on a cycle is
 * infinite: any deadline in the seconds range still catches it.
 */
class CycleGuardDeadlineTest extends TestCase
{
    private const MINIMUM_GUARD_MS = 10_000;

    public function test_the_cycle_guard_deadline_leaves_room_for_a_loaded_machine(): void
    {
        $source = (string) file_get_contents(base_path('tests/Feature/UnitModelTest.php'));

        preg_match_all('/withStatementTimeout\((\d+)/', $source, $matches);

        $this->assertNotEmpty(
            $matches[1],
            'the cycle tests must still guard themselves with a statement_timeout'
        );

        foreach ($matches[1] as $milliseconds) {
            $this->assertGreaterThanOrEqual(
                self::MINIMUM_GUARD_MS,
                (int) $milliseconds,
                "a {$milliseconds}ms statement_timeout fails a ~2ms query under CPU load; "
                .'the guard exists to stop an infinite UNION ALL, not to measure speed'
            );
        }
    }
}
