<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1814 — §3 of install-guid.md is the deployer's only branch
 * instruction, and it named a branch that does not exist upstream (`dev`)
 * beside a stale mirror (`main`, a periodic snapshot of `beta`). A
 * copy-paste of that line failed on a fresh clone; guessing `main`
 * deployed code days behind `beta`.
 *
 * Like InstallGuideCspGateTest, this reads the SHIPPED text: an unread
 * command in the guide is the failure mode.
 */
class InstallGuideBranchCheckoutTest extends TestCase
{
    private function guide(): string
    {
        $guide = file_get_contents(base_path('install-guid.md'));

        $this->assertIsString($guide, 'install-guid.md must be readable');

        return $guide;
    }

    private function getTheCodeSection(): string
    {
        $guide = $this->guide();

        $start = strpos($guide, '## 3. ');
        $this->assertNotFalse($start, 'install-guid.md has no `## 3. ` heading');

        $end = strpos($guide, '## 4. ', $start);
        $this->assertNotFalse($end, 'install-guid.md has no `## 4. ` heading after `## 3. `');

        return substr($guide, $start, $end - $start);
    }

    public function test_the_get_the_code_step_checks_out_the_base_branch(): void
    {
        // Anchored to the whole line: a trailing `# or main / beta` comment
        // must not be able to satisfy this, and trailing whitespace fails.
        $this->assertMatchesRegularExpression(
            '/^git checkout beta$/m',
            $this->getTheCodeSection(),
            '§3 must check out the base branch, exactly once, with no alternatives'
        );
    }

    public function test_no_other_branch_is_named_as_a_deploy_target(): void
    {
        $guide = $this->guide();

        $this->assertStringNotContainsString('git checkout dev', $guide);
        $this->assertStringNotContainsString('git checkout main', $guide);
        $this->assertStringNotContainsString('or main / beta', $guide);
    }
}
