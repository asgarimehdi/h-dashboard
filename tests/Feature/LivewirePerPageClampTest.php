<?php

namespace Tests\Feature;

use App\Models\Hardware;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1727 — `perPage` is public Livewire state and reaches `paginate()`
 * unclamped in 17 components.
 *
 * Why a clamp and not a 422: the web has no validation channel for a property
 * write. `paginate(0)` does NOT divide by zero on this Laravel version — it
 * silently falls back to `Model::$perPage` (15) — and a negative value is
 * dropped by `Query\Builder::limit()` (`if ($value >= 0)`), returning the whole
 * table in one response. So these tests pin the clamp (0 → 1, -5 → 1), never a
 * 500.
 *
 * The single source of truth is `App\Traits\ClampsPageSize`; the static guard
 * keeps a newly added paginated component from reintroducing the bare call.
 */
final class LivewirePerPageClampTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** The trait lives in one file only — no per-component copies. */
    private const TRAIT_PATH = 'app/Traits/ClampsPageSize.php';

    /** 17 components clamp today (16 fixed + `disease/index`, which already did). */
    private const EXPECTED_CLAMPED_VIEWS = 17;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function perPageCases(): array
    {
        return [
            'zero falls to the floor' => [0, 1],
            'negative falls to the floor' => [-5, 1],
            'floor boundary' => [1, 1],
            'legal default' => [20, 20],
            'legal report default' => [25, 25],
            'ceiling boundary' => [100, 100],
            'very large clamps to the ceiling' => [10000, 100],
        ];
    }

    #[DataProvider('perPageCases')]
    public function test_hardware_index_clamps_per_page(int $set, int $expected): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        Hardware::factory()->create(['n_code' => $user->n_code]);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->set('perPage', $set)
            ->assertOk()
            ->assertSet('perPage', $expected)
            ->assertViewHas('hardwares', fn ($rows) => $rows->perPage() === $expected
                && $rows->count() <= $expected);
    }

    #[DataProvider('perPageCases')]
    public function test_users_index_clamps_per_page(int $set, int $expected): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);

        Livewire::actingAs($user)
            ->test('users.index')
            ->set('perPage', $set)
            ->assertOk()
            ->assertSet('perPage', $expected)
            ->assertViewHas('users', fn ($rows) => $rows->perPage() === $expected
                && $rows->count() <= $expected);
    }

    #[DataProvider('perPageCases')]
    public function test_disease_index_clamps_per_page(int $set, int $expected): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_diseases']);

        Livewire::actingAs($user)
            ->test('disease.index')
            ->set('perPage', $set)
            ->assertOk()
            ->assertSet('perPage', $expected)
            ->assertViewHas('cases', fn ($rows) => $rows->perPage() === $expected
                && $rows->count() <= $expected);
    }

    public function test_changing_per_page_starts_a_new_result_set(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        Hardware::factory()->count(3)->create(['n_code' => $user->n_code]);

        Livewire::actingAs($user)
            ->test('hardware.index')
            ->set('perPage', 1)
            ->call('setPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('perPage', 100)
            ->assertSet('perPage', 100)
            // Without the reset the user lands on a page that no longer exists.
            ->assertSet('paginators.page', 1);
    }

    public function test_no_component_passes_the_raw_property_to_paginate(): void
    {
        $offenders = [];

        foreach ($this->viewFiles() as $path) {
            $code = $this->stripComments(File::get(base_path($path)));

            if (str_contains($code, 'paginate($this->perPage)')) {
                $offenders[] = $path;
            }
        }

        expect($offenders)->toBe([], implode("\n", array_merge(
            ['perPage به‌صورت خام به paginate() می‌رسد — از $this->pageSize() استفاده کن:'],
            $offenders,
        )));
    }

    public function test_every_paginated_view_uses_the_shared_trait(): void
    {
        $offenders = [];
        $clamped = [];

        foreach ($this->viewFiles() as $path) {
            $code = $this->stripComments(File::get(base_path($path)));

            if (! preg_match('/public\s+int\s+\$perPage/', $code)) {
                continue;
            }

            if (! str_contains($code, 'use ClampsPageSize;')
                || ! str_contains($code, 'use App\\Traits\\ClampsPageSize;')) {
                $offenders[] = $path;
            }

            if (str_contains($code, 'paginate($this->pageSize())')) {
                $clamped[] = $path;
            }
        }

        expect($offenders)->toBe([], implode("\n", array_merge(
            ['کامپوننت دارای perPage بدون use ClampsPageSize — clamp در یک نقطه بماند:'],
            $offenders,
        )));

        expect($clamped)->toHaveCount(self::EXPECTED_CLAMPED_VIEWS);
    }

    public function test_page_size_is_defined_once_in_the_shared_trait(): void
    {
        $definitions = [];

        foreach (array_merge($this->viewFiles(), ['app/Traits/ClampsPageSize.php']) as $path) {
            $code = $this->stripComments(File::get(base_path($path)));

            if (str_contains($code, 'function pageSize')) {
                $definitions[] = $path;
            }
        }

        expect($definitions)->toBe([self::TRAIT_PATH]);
    }

    public function test_trait_declares_the_api_ceiling(): void
    {
        $code = File::get(base_path(self::TRAIT_PATH));

        expect($code)->toContain('MAX_PER_PAGE = 100')
            ->and($code)->toContain('max(1, min(self::MAX_PER_PAGE, $this->perPage))');
    }

    /**
     * @return array<int, string>
     */
    private function viewFiles(): array
    {
        $paths = [];

        foreach (File::allFiles(base_path('resources/views/livewire')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
        }

        sort($paths);

        return $paths;
    }

    /**
     * The bare spelling is quoted in docblocks/comments as documentation, so a
     * guard without stripping flags its own explanation.
     */
    private function stripComments(string $source): string
    {
        $stripped = preg_replace([
            '~/\*.*?\*/~s',
            '~//[^\n]*~',
            '~\{\{--.*?--\}\}~s',
        ], '', $source);

        return $stripped ?? $source;
    }
}
