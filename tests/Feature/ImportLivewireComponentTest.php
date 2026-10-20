<?php

namespace Tests\Feature;

use App\Imports\HardwareImport;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\User;
use App\Support\ImportPreviewStore;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HardwareImport::class);

/**
 * Issue #1168: the parsed import preview lives in the server-side cache behind
 * a public preview token — never in the Livewire snapshot. These tests lock
 * the contract the expert comment pinned: status-derived defaults (never
 * `auto`), a sparse override map, page-scoped bulk actions and a light payload.
 */
class ImportLivewireComponentTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        ['user' => $user] = $this->createUserWithUnit([
            'manage_hardware',
            'manage_personnel',
            'kargozini',
        ]);

        $this->user = $user;
    }

    // ==================== helpers ====================

    /**
     * Put a synthetic preview in the same store `importPreview()` uses.
     *
     * @param  array<int, array<string, mixed>>  $preview
     * @param  array<string, mixed>  $overrides
     */
    private function storePreview(array $preview, array $overrides = []): string
    {
        $token = (string) Str::uuid();

        ImportPreviewStore::put($token, array_merge([
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'changes' => [],
            'preview' => $preview,
        ], $overrides));

        return $token;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function hardwarePreview(array $rows): string
    {
        return $this->storePreview($rows);
    }

    /**
     * The toast text is a JS effect, not rendered HTML — decode it.
     */
    private function toastTitles($component): array
    {
        $titles = [];

        foreach (array_column($component->effects['xjs'] ?? [], 'expression') as $expression) {
            $inner = json_decode(substr($expression, strlen('toast('), -1), true);

            if (is_array($inner) && isset($inner['toast']['title'])) {
                $titles[] = (string) $inner['toast']['title'];
            }
        }

        return $titles;
    }

    /**
     * @return array<string, string>
     */
    private function selectedActions($component): array
    {
        $method = new ReflectionMethod($component->instance(), 'getSelectedActions');
        $method->setAccessible(true);

        return $method->invoke($component->instance());
    }

    private function hardwareCsv(int $rows, string $nCode, string $prefix = 'PAGED'): UploadedFile
    {
        $csv = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";

        for ($i = 0; $i < $rows; $i++) {
            $csv .= $nCode."\t".$prefix.'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)
                ."\tpc\tWindows 11\tCPU\t8192\tHDD 1TB\tAA:BB:CC:DD:EE:".str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT)."\n";
        }

        return UploadedFile::fake()->createWithContent('hardware.csv', $csv);
    }

    private function personsCsv(int $rows, int $unitId): UploadedFile
    {
        $tsv = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";

        for ($i = 0; $i < $rows; $i++) {
            $tsv .= '90000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)
                ."\tنام\tخانواده\t1\t1\t1\t1\t{$unitId}\n";
        }

        return UploadedFile::fake()->createWithContent('persons.csv', $tsv);
    }

    // ==================== reset / cancel ====================

    public function test_hardware_import_reset_form_clears_state(): void
    {
        Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->assertSet('showPreview', false)
            ->assertSet('previewToken', null)
            ->call('resetForm')
            ->assertSet('showPreview', false)
            ->assertSet('previewToken', null)
            ->assertSet('rowActions', []);
    }

    public function test_hardware_import_cancel_import_forgets_the_cached_preview(): void
    {
        $token = $this->hardwarePreview([['id' => 1, 'status' => 'create', 'pc_name' => 'X']]);

        $component = Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->set('previewToken', $token)
            ->set('rowActions', ['row_2' => 'skip'])
            ->set('showPreview', true)
            ->call('cancelImport')
            ->assertSet('showPreview', false)
            ->assertSet('previewToken', null)
            ->assertSet('rowActions', []);

        $this->assertNull(ImportPreviewStore::get($token));
        $this->assertArrayNotHasKey('previewData', $component->snapshot['data']);
    }

    public function test_hardware_import_confirm_without_results_shows_error(): void
    {
        Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->call('confirmImport')
            ->assertSet('importResults', null);
    }

    // ==================== defaults + sparse overrides ====================

    public function test_hardware_import_get_selected_actions_maps_statuses_and_merges_overrides(): void
    {
        $token = $this->hardwarePreview([
            ['id' => 10, 'status' => 'create', 'pc_name' => 'X'],
            ['id' => 11, 'status' => 'update', 'pc_name' => 'Y'],
            ['id' => 12, 'status' => 'unchanged', 'pc_name' => 'Z'],
            ['status' => 'error', 'message' => 'missing person'],
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->set('previewToken', $token)
            ->set('rowActions', ['row_3' => 'skip']);

        $actions = $this->selectedActions($component);

        $this->assertSame('create', $actions['row_2']);
        $this->assertSame('skip', $actions['row_3'], 'the sparse override must win over the status default');
        $this->assertSame('skip', $actions['row_4']);
        $this->assertArrayNotHasKey('row_5', $actions, 'an error row without keys must never receive an action');
    }

    public function test_persons_import_get_selected_actions_maps_statuses_and_merges_overrides(): void
    {
        $token = $this->storePreview([
            ['n_code' => '111', 'status' => 'create'],
            ['n_code' => '222', 'status' => 'update'],
            ['status' => 'error', 'message' => 'x', 'data' => ['n_code' => '333']],
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('kargozini.import-persons.import-persons')
            ->set('previewToken', $token)
            ->set('rowActions', ['row_3' => 'skip']);

        $actions = $this->selectedActions($component);

        $this->assertSame('create', $actions['row_2']);
        $this->assertSame('skip', $actions['row_3']);
        $this->assertArrayNotHasKey('row_4', $actions);
    }

    public function test_persons_import_cancel_resets_and_forgets_the_cached_preview(): void
    {
        $token = $this->storePreview([['n_code' => '111', 'status' => 'create']]);

        Livewire::actingAs($this->user)
            ->test('kargozini.import-persons.import-persons')
            ->set('previewToken', $token)
            ->set('showPreview', true)
            ->call('cancelImport')
            ->assertSet('showPreview', false)
            ->assertSet('previewToken', null)
            ->assertSet('rowActions', []);

        $this->assertNull(ImportPreviewStore::get($token));
    }

    // ==================== payload lock ====================

    public function test_hardware_import_snapshot_carries_no_preview_rows(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        $rows = [];

        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['id' => $i + 1, 'status' => 'update', 'pc_name' => 'PAYLOAD-'.$i];
        }

        $component = Livewire::test('hardware.import-hardware.import-hardware')
            ->set('previewToken', $this->hardwarePreview($rows))
            ->set('showPreview', true);

        $data = $component->snapshot['data'];
        $snapshotJson = (string) json_encode($component->snapshot);

        $this->assertArrayNotHasKey('previewData', $data);
        $this->assertStringNotContainsString('"previewData"', $snapshotJson);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->assertLessThan(200, count($value), "snapshot key [{$key}] carries the whole preview");
            }
        }

        $html = $component->html();

        $this->assertSame(50, $component->instance()->previewPage->count());
        $this->assertSame(50, substr_count($html, 'PAYLOAD-'));

        $this->assertLessThan(4000, strlen($snapshotJson), 'the snapshot payload grew — preview rows slipped back into public state');
        // MaryUI emits verbose block markers per cell, so the absolute floor is
        // high; 50 rows render ~530KB while the old 200-row table rendered ~1.4MB.
        $this->assertLessThan(700000, strlen($html), 'the rendered page grew — the preview is no longer windowed');
    }

    public function test_persons_import_snapshot_carries_no_preview_rows(): void
    {
        $rows = [];

        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['n_code' => '90000000'.$i, 'status' => 'create'];
        }

        $component = Livewire::actingAs($this->user)
            ->test('kargozini.import-persons.import-persons')
            ->set('previewToken', $this->storePreview($rows))
            ->set('showPreview', true);

        $this->assertArrayNotHasKey('previewData', $component->snapshot['data']);
        $this->assertLessThan(4000, strlen((string) json_encode($component->snapshot)));
    }

    // ==================== pagination + page-scoped actions ====================

    public function test_hardware_import_preview_is_paginated(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        $component = Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $this->hardwareCsv(60, $user->person->n_code))
            ->call('importPreview')
            ->assertSet('showPreview', true);

        $component->assertSee('PAGED-000')->assertDontSee('PAGED-050');

        $component->call('setPage', 2)
            ->assertSee('PAGED-050')
            ->assertSee('PAGED-059')
            ->assertDontSee('PAGED-000');
    }

    public function test_hardware_import_bulk_action_only_touches_the_current_page(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $this->hardwareCsv(60, $user->person->n_code))
            ->call('importPreview')
            ->call('setPage', 2)
            ->call('applyBulkAction', 'skip')
            ->call('confirmImport');

        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PAGED-000']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PAGED-049']);
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PAGED-050']);
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PAGED-059']);
    }

    public function test_hardware_import_row_override_on_page_two_is_applied_on_confirm(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $this->hardwareCsv(60, $user->person->n_code))
            ->call('importPreview')
            ->call('setPage', 2)
            // first data row is row_2; page two starts at row_52
            ->call('setRowAction', 52, 'skip')
            ->call('confirmImport');

        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PAGED-050']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PAGED-051']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PAGED-000']);
    }

    public function test_hardware_import_untouched_rows_keep_their_status_derived_defaults(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        $nCode = $user->person->n_code;

        Hardware::create(['n_code' => $nCode, 'pc_name' => 'KEEP-UPDATE', 'cpu' => 'Old CPU', 'type' => 'pc', 'os' => 'Windows 10']);
        Hardware::create(['n_code' => $nCode, 'pc_name' => 'KEEP-SAME', 'cpu' => 'Same CPU', 'type' => 'pc', 'os' => 'Windows 10']);

        $csv = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csv .= "{$nCode}\tKEEP-UPDATE\tpc\tWindows 10\tNew CPU\t\t\t\n";
        $csv .= "{$nCode}\tKEEP-SAME\tpc\tWindows 10\tSame CPU\t\t\t\n";
        $csv .= "{$nCode}\tNEW-ROW\tpc\tWindows 11\tFresh CPU\t8192\tSSD\t\n";
        $csv .= "0000000000\tERROR-ROW\tpc\tWindows 11\tBroken\t\t\t\n";
        $file = UploadedFile::fake()->createWithContent('hardware.csv', $csv);

        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $file)
            ->call('importPreview')
            ->call('confirmImport');

        $this->assertDatabaseHas('hardwares', ['pc_name' => 'KEEP-UPDATE', 'cpu' => 'New CPU']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'KEEP-SAME', 'cpu' => 'Same CPU']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'NEW-ROW']);
        // The error row defaults to skip — the regression an `auto` default would cause.
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'ERROR-ROW']);
    }

    public function test_persons_import_untouched_rows_keep_their_status_derived_defaults(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        Person::create([
            'n_code' => '9000000010',
            'f_name' => 'قدیمی',
            'l_name' => 'کاربر',
            'u_id' => $unit->id,
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
        ]);

        $tsv = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $tsv .= "9000000010\tجدید\tکاربر\t1\t1\t1\t1\t{$unit->id}\n"; // update
        $tsv .= "9000000020\tتازه\tوارد\t1\t1\t1\t1\t{$unit->id}\n"; // create
        $tsv .= "9000000030\tخطا\tدار\t1\t1\t1\t1\t\n"; // error: empty u_id

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', UploadedFile::fake()->createWithContent('persons.csv', $tsv))
            ->call('importPreview')
            ->call('confirmImport');

        $this->assertDatabaseHas('persons', ['n_code' => '9000000010', 'f_name' => 'جدید']);
        $this->assertDatabaseHas('persons', ['n_code' => '9000000020']);
        $this->assertDatabaseMissing('persons', ['n_code' => '9000000030']);
    }

    public function test_persons_import_bulk_action_only_touches_the_current_page(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $this->personsCsv(60, $unit->id))
            ->call('importPreview')
            ->assertSee('9000000000')
            ->assertDontSee('9000000050')
            ->call('setPage', 2)
            ->assertSee('9000000050')
            ->call('applyBulkAction', 'skip')
            ->call('confirmImport');

        $this->assertDatabaseHas('persons', ['n_code' => '9000000000']);
        $this->assertDatabaseHas('persons', ['n_code' => '9000000049']);
        $this->assertDatabaseMissing('persons', ['n_code' => '9000000050']);
        $this->assertDatabaseMissing('persons', ['n_code' => '9000000059']);
    }

    // ==================== expired token ====================

    public function test_confirm_with_an_expired_preview_token_shows_a_friendly_error(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->set('importResults', ['created' => 0, 'updated' => 0, 'skipped' => 0])
            ->set('previewToken', 'expired-token')
            ->set('showPreview', true)
            ->call('confirmImport')
            ->assertSet('showPreview', false);

        $this->assertStringContainsString('منقضی', implode(' | ', $this->toastTitles($component)));
    }

    // ==================== stats ====================

    public function test_preview_stats_read_from_the_cached_preview(): void
    {
        $token = $this->storePreview([
            ['id' => 1, 'status' => 'create', 'pc_name' => 'A'],
            ['id' => 2, 'status' => 'update', 'pc_name' => 'B'],
            ['id' => 3, 'status' => 'unchanged', 'pc_name' => 'C'],
            ['status' => 'error', 'message' => 'x'],
        ], ['errors' => [['row' => 5, 'error' => 'x']]]);

        $component = Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->set('previewToken', $token);

        $this->assertSame([
            'total' => 4,
            'new' => 1,
            'updated' => 1,
            'unchanged' => 1,
            'errors' => 1,
        ], $component->instance()->previewStats);
    }
}
