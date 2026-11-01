<?php

namespace Tests\Feature;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Step-1 proof for issue #1350: the cycle tests must TERMINATE.
 *
 * An unbounded PHP recursion dies by memory exhaustion and takes the test
 * worker with it, and PHPUnit has no per-test wall-clock timeout, so the
 * termination proof runs each cycle-test group in a SEPARATE process with a
 * wall-clock cap (30s) and a low memory cap (256M). Before the guard the
 * child never returns and the wrapper fails on the timeout or the child's
 * non-zero exit; after the guard the same child exits 0 in seconds. The
 * groups run directly in the suite too — this file only proves they cannot
 * hang it.
 *
 * Only loadSubtree and buildTree have subprocess groups. The orgChart
 * $format cannot be entered into a cycle under the current root rule: roots
 * are units with no in-scope parent, and every cycle member's parent is
 * another cycle member, so no traversal path from a root reaches a cycle
 * (closing a cycle severs the entry link — a unit has one parent_id). Its
 * guard is defense-in-depth, and its contract is pinned directly:
 * a pure in-scope cycle returns [], and a cycle below the root stays out
 * of the tree. Those two tests run only in the suite, not in a subprocess.
 */
class CycleGuardSubprocessTest extends TestCase
{
    public function test_subtree_cycle_tests_terminate_in_a_subprocess(): void
    {
        $this->assertCycleProbeTerminates(
            'tests/Feature/HrApiTest.php',
            'test_org_chart_subtree_terminates_on_a_two_node_cycle|test_org_chart_subtree_terminates_on_a_self_parent|test_org_chart_subtree_terminates_on_a_reachable_cycle'
        );
    }

    public function test_build_tree_cycle_tests_terminate_in_a_subprocess(): void
    {
        $this->assertCycleProbeTerminates(
            'tests/Feature/UnitModelTest.php',
            'test_build_tree_terminates_when_a_root_is_in_a_cycle|test_build_tree_terminates_on_a_self_parent_root'
        );
    }

    private function assertCycleProbeTerminates(string $testFile, string $filter): void
    {
        $process = new Process(
            [PHP_BINARY, '-d', 'memory_limit=256M', 'vendor/bin/pest', '--no-coverage', "--filter={$filter}", $testFile],
            base_path()
        );
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->fail("Cycle probe [{$filter}] did not terminate within 30s — the traversal hangs (issue #1350, step 1).");
        }

        $this->assertTrue(
            $process->isSuccessful(),
            "Cycle probe [{$filter}] failed in a subprocess:\n".substr($process->getOutput().$process->getErrorOutput(), -2000)
        );
    }
}
