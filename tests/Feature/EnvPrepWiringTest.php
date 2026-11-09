<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1294 — `.env.testing` is built in ONE place.
 *
 * The same seven-line block used to be copy-pasted into three jobs of
 * `.github/workflows/test.yml` (`test`, `mutation`, `test-php86`). Two of those
 * jobs are `continue-on-error`, so a re-introduced copy would keep them green
 * while the single source silently went stale. Colour is not evidence; this
 * text guard is what turns a regression red.
 *
 * Known limit (accepted): the third assertion is a plain substring count, so a
 * future job that mentions that value in a comment for an unrelated reason
 * would trip it. That is a false positive, not a reason to drop the guard.
 */
class EnvPrepWiringTest extends TestCase
{
    public function test_the_env_testing_prep_lives_in_one_script_and_is_wired_into_all_three_jobs(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/test.yml'));
        $sweep = (string) file_get_contents(base_path('.github/workflows/theme-sweep-35.yml'));
        $script = (string) file_get_contents(base_path('scripts/build-env-testing.sh'));

        $this->assertSame(
            3,
            substr_count($workflow, 'run: bash scripts/build-env-testing.sh'),
            'all three consuming jobs (test, mutation, test-php86) must call the single script'
        );

        $this->assertSame(
            0,
            substr_count($workflow, 'cp .env.example .env.testing'),
            'an inline copy came back: .env.testing is built only by scripts/build-env-testing.sh'
        );

        $this->assertSame(
            0,
            substr_count($workflow, 'DB_DATABASE=h_dashboard_test'),
            'an inline sed came back: .env.testing is built only by scripts/build-env-testing.sh'
        );

        $this->assertSame(
            0,
            substr_count($sweep, 'env.testing'),
            'the theme sweep builds .env, not .env.testing (issue #1294 corrections)'
        );

        $this->assertStringContainsString(
            "grep -q '^CACHE_STORE=' .env.testing",
            $script,
            "the CACHE_STORE fallback is required: without it the parallel suite shares spatie's permission cache"
        );
    }
}
