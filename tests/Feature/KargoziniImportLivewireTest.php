<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class KargoziniImportLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Build a TSV string (PersonImport uses tab-delimited via getCsvSettings).
     */
    protected function buildTsv(array $rows): string
    {
        $header = implode("\t", ['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id', 'u_id']);
        $lines = [$header];

        foreach ($rows as $row) {
            $lines[] = implode("\t", [
                $row['n_code'] ?? '',
                $row['f_name'] ?? '',
                $row['l_name'] ?? '',
                $row['t_id'] ?? '',
                $row['e_id'] ?? '',
                $row['s_id'] ?? '',
                $row['r_id'] ?? '',
                $row['u_id'] ?? '',
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Create a file as an Illuminate\Http\Testing\File (has public $name
     * required by Livewire's testing upload simulation).
     */
    protected function createUploadFile(string $content, string $name = 'test.csv'): File
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    // ==================== Auth / smoke ====================

    public function test_guest_302(): void
    {
        $this->get('/kargozini/persons/import')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get('/kargozini/persons/import')->assertStatus(403);
    }

    // ایشو #775: import نوشتن است — فقط `manage_personnel`؛ دارنده‌ی صرفِ
    // `kargozini` دیگر به صفحه‌ی import دسترسی ندارد (هم‌راستا با API).
    public function test_kargozini_only_holder_gets_403_on_import(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        $this->actingAs($user);

        $this->get('/kargozini/persons/import')->assertStatus(403);
    }

    public function test_manage_personnel_holder_can_open_import(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $this->get('/kargozini/persons/import')->assertStatus(200);
    }

    public function test_renders(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->assertStatus(200)
            ->assertSee('ورود اطلاعات پرسنل از فایل اکسل');
    }

    // ==================== Mount ====================

    public function test_mount_initializes_clean_state(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null)
            ->assertSet('compareKey', 'n_code')
            ->assertSet('previewToken', null)
            ->assertSet('rowActions', []);
    }

    // ==================== File validation ====================

    public function test_file_validation_rejects_missing_file(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->call('importPreview')
            ->assertHasErrors(['file']);
    }

    public function test_file_validation_rejects_invalid_mime(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $file = UploadedFile::fake()->createWithContent('test.txt', 'hello');

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertHasErrors(['file']);
    }

    // ==================== Valid preview ====================

    public function test_valid_preview_populates_data(): void
    {
        // ایشو #814: import نوشتن است و داخل کامپوننت هم `manage_personnel` می‌خواهد.
        $result = $this->createUserWithUnit(['manage_personnel']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        $unitId = $unit->id;

        $tsv = $this->buildTsv([[
            'n_code' => '9000000001', 'f_name' => 'علی', 'l_name' => 'محمدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unitId,
        ]]);

        $file = $this->createUploadFile($tsv);

        $component = Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true)
            ->assertSee('علی')
            ->assertSee('محمدی');

        $this->assertSame(1, $component->instance()->previewStats['total']);
        $this->assertSame(1, $component->instance()->previewStats['new']);
    }

    // ==================== Confirm without preview ====================

    public function test_confirm_without_preview_shows_error(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->call('confirmImport')
            ->assertSet('importResults', null);
    }

    // ==================== Cancel clears ====================

    public function test_cancel_clears_state(): void
    {
        $result = $this->createUserWithUnit(['manage_personnel']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        $unitId = $unit->id;

        $tsv = $this->buildTsv([[
            'n_code' => '9000000003', 'f_name' => 'رضا', 'l_name' => 'کریمی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unitId,
        ]]);

        $file = $this->createUploadFile($tsv);

        $component = Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true)
            ->call('cancelImport')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null);

        $this->assertSame(0, $component->instance()->previewStats['total']);
    }

    // ==================== Edge cases ====================

    public function test_zero_rows_shows_total_zero(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        // TSV with header only (no data rows)
        $tsv = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";

        $file = $this->createUploadFile($tsv);

        $component = Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true);

        $this->assertSame(0, $component->instance()->previewStats['total']);
    }

    public function test_exception_during_preview_shows_error(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        // Upload a file that is not valid Excel/CSV to trigger an exception
        $file = UploadedFile::fake()->createWithContent('bad.xlsx', 'not-an-excel-file');

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            // Should stay on upload form (showPreview stays false after exception)
            ->assertSet('showPreview', false);
    }

    // ==================== Help modal ====================

    public function test_help_modal_toggles(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->assertSet('showHelpModal', false)
            ->set('showHelpModal', true)
            ->assertSet('showHelpModal', true);
    }

    // ==================== #1167: bulk write contract ====================

    public function test_bulk_person_import_writes_500_rows_in_chunks_and_bumps_cache_once(): void
    {
        $result = $this->createUserWithUnit(['manage_personnel']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($user);

        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $nCode = '51'.str_pad((string) $i, 8, '0', STR_PAD_LEFT);
            Person::create([
                'n_code' => $nCode,
                'f_name' => 'قبلی',
                'l_name' => 'نام',
                't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1,
                'u_id' => $unit->id,
            ]);
            $rows[] = [
                'n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'رضایی',
                't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
            ];
        }
        for ($i = 0; $i < 250; $i++) {
            $rows[] = [
                'n_code' => '52'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'f_name' => 'علی', 'l_name' => 'رضایی',
                't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
            ];
        }

        $file = $this->createUploadFile($this->buildTsv($rows));

        // Reset versions after seeding the 250 existing persons — each model
        // save bumps a namespace, so the baseline must be taken last.
        Cache::flush();

        $component = Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('importStats.total', 500)
            ->assertSet('importStats.new', 250)
            ->assertSet('importStats.updated', 250);

        // A per-row write path would need >500 statements here.
        $this->assertQueryCount(60, function () use ($component) {
            $component->call('confirmImport');
        });

        $this->assertSame(250, Person::where('n_code', 'like', '51%')->where('f_name', 'علی')->count());
        $this->assertSame(250, Person::where('n_code', 'like', '52%')->count());

        foreach (['hr_stats', 'dashboard', 'maps', 'gis'] as $namespace) {
            $this->assertSame(1, Cache::get($namespace.'_version', 0), "{$namespace} must bump once.");
        }
    }

    public function test_bulk_person_path_normalizes_names(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        // Arabic Yeh (U+064A) must fold to Persian Yeh (U+06CC).
        $file = $this->createUploadFile($this->buildTsv([[
            'n_code' => '9000000200', 'f_name' => "عل\u{064A}", 'l_name' => 'رضایی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]]));

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->call('confirmImport');

        $this->assertDatabaseHas('persons', [
            'n_code' => '9000000200',
            'f_name' => "عل\u{06CC}",
        ]);
    }
}
