<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

class KargoziniImportLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        // Resync Postgres sequences after explicit ID inserts
        DB::select("SELECT setval('tahsils_id_seq', (SELECT COALESCE(MAX(id),1) FROM tahsils))");
        DB::select("SELECT setval('estekhdams_id_seq', (SELECT COALESCE(MAX(id),1) FROM estekhdams))");
        DB::select("SELECT setval('semats_id_seq', (SELECT COALESCE(MAX(id),1) FROM semats))");
        DB::select("SELECT setval('radifs_id_seq', (SELECT COALESCE(MAX(id),1) FROM radifs))");
    }

    protected function createUserWithUnit(string $permission): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->givePermissionTo($permission);

        Session::put('current_unit_id', $unit->id);

        return $user;
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
        $user = $this->createUserWithUnit('manage_users');
        $this->actingAs($user);

        $this->get('/kargozini/persons/import')->assertStatus(403);
    }

    public function test_renders(): void
    {
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->assertStatus(200)
            ->assertSee('ورود اطلاعات پرسنل از فایل اکسل');
    }

    // ==================== Mount ====================

    public function test_mount_initializes_clean_state(): void
    {
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null)
            ->assertSet('compareKey', 'n_code')
            ->assertSet('importStats.total', 0);
    }

    // ==================== File validation ====================

    public function test_file_validation_rejects_missing_file(): void
    {
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->call('importPreview')
            ->assertHasErrors(['file']);
    }

    public function test_file_validation_rejects_invalid_mime(): void
    {
        $user = $this->createUserWithUnit('kargozini');
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
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        $unitId = $user->units()->first()->id;

        $tsv = $this->buildTsv([[
            'n_code' => '9000000001', 'f_name' => 'علی', 'l_name' => 'محمدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unitId,
        ]]);

        $file = $this->createUploadFile($tsv);

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true)
            ->assertSet('importStats.total', 1)
            ->assertSet('importStats.new', 1)
            ->assertSee('علی')
            ->assertSee('محمدی');
    }

    // ==================== Confirm without preview ====================

    public function test_confirm_without_preview_shows_error(): void
    {
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->call('confirmImport')
            ->assertSet('importResults', null);
    }

    // ==================== Cancel clears ====================

    public function test_cancel_clears_state(): void
    {
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        $unitId = $user->units()->first()->id;

        $tsv = $this->buildTsv([[
            'n_code' => '9000000003', 'f_name' => 'رضا', 'l_name' => 'کریمی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unitId,
        ]]);

        $file = $this->createUploadFile($tsv);

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true)
            ->call('cancelImport')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null)
            ->assertSet('importStats.total', 0);
    }

    // ==================== Edge cases ====================

    public function test_zero_rows_shows_total_zero(): void
    {
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        // TSV with header only (no data rows)
        $tsv = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";

        $file = $this->createUploadFile($tsv);

        Livewire::test('kargozini.import-persons.import-persons')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true)
            ->assertSet('importStats.total', 0);
    }

    public function test_exception_during_preview_shows_error(): void
    {
        $user = $this->createUserWithUnit('kargozini');
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
        $user = $this->createUserWithUnit('kargozini');
        $this->actingAs($user);

        Livewire::test('kargozini.import-persons.import-persons')
            ->assertSet('showHelpModal', false)
            ->set('showHelpModal', true)
            ->assertSet('showHelpModal', true);
    }
}
