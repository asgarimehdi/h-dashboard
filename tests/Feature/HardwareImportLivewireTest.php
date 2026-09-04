<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class HardwareImportLivewireTest extends TestCase
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
    }

    protected function createUserWithUnit(): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo('manage_hardware');
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    // ==================== Smoke tests ====================

    public function test_renders(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('hardware.import-hardware.import-hardware')
            ->assertStatus(200)
            ->assertSee('پیش‌نمایش و مقایسه');
    }

    public function test_guest_302(): void
    {
        $this->get('/hardware/import')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        $user = $this->createUserWithUnit();
        DB::table('model_has_permissions')
            ->where('permission_id', DB::table('permissions')->where('name', 'manage_hardware')->value('id'))
            ->where('model_id', $user->id)
            ->delete();
        $this->actingAs($user);

        $this->get('/hardware/import')->assertStatus(403);
    }

    // ==================== Validation tests ====================

    public function test_preview_requires_file(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('hardware.import-hardware.import-hardware')
            ->call('importPreview')
            ->assertHasErrors(['file']);
    }

    public function test_preview_max_size(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        // Create a real small file but report oversized via sizeToReport
        // Livewire max is 12288KB, component max is 10240KB
        // File at 10241KB passes Livewire but fails component validation
        $tempPath = tempnam(sys_get_temp_dir(), 'hw_test_');
        file_put_contents($tempPath, 'test');
        $file = new File('test.xlsx', fopen($tempPath, 'r'));
        $file->sizeToReport = 10241 * 1024; // 10241 KB in bytes

        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $file)
            ->call('importPreview')
            ->assertHasErrors(['file']);

        @unlink($tempPath);
    }

    public function test_preview_mime(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        // .txt file → MIME text/plain (not in xlsx,xls,csv)
        $file = UploadedFile::fake()->createWithContent('test.txt', 'test data');

        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $file)
            ->call('importPreview')
            ->assertHasErrors(['file']);
    }

    // ==================== Interaction tests ====================

    public function test_compare_key_repreview(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $nCode = Person::where('f_name', 'تست')->first()->n_code;
        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "{$nCode}\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
        $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

        // Run preview with default compare key (pc_name)
        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true)
            ->assertSet('compareKey', 'pc_name')
            ->assertSet('importStats.total', 1)
            // Change compare key to 'mac' → re-fires importPreview
            ->set('compareKey', 'mac')
            ->assertSet('compareKey', 'mac')
            ->assertSet('showPreview', true)
            ->assertSet('importStats.total', 1);
    }

    public function test_confirm_empty_error(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('hardware.import-hardware.import-hardware')
            ->call('confirmImport')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null);
    }

    public function test_cancel_resets(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $nCode = Person::where('f_name', 'تست')->first()->n_code;
        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "{$nCode}\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
        $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

        // Run preview to populate state, then cancel → all resets
        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $file)
            ->call('importPreview')
            ->assertSet('showPreview', true)
            ->assertSet('importStats.total', 1)
            ->call('cancelImport')
            ->assertSet('showPreview', false)
            ->assertSet('importResults', null)
            ->assertSet('file', null)
            ->assertSet('importStats.total', 0)
            ->assertSet('importStats.new', 0);
    }

    public function test_row_override_skip(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $nCode = Person::where('f_name', 'تست')->first()->n_code;

        // Create an existing hardware record to match against (will be 'update')
        Hardware::create([
            'n_code' => $nCode, 'pc_name' => 'PC-EXISTING', 'cpu' => 'Old CPU',
            'type' => 'pc', 'os' => 'Windows 10',
        ]);

        // CSV with 2 rows: one update (existing), one create (new)
        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "{$nCode}\tPC-EXISTING\tpc\tWindows 11\tNew CPU\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
        $csvContent .= "{$nCode}\tPC-NEW\tpc\tWindows 10\tIntel i3\t8192\tHDD 1TB\tAA:BB:CC:DD:EE:FF\n";
        $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

        // Preview → override new row to skip → confirm
        Livewire::test('hardware.import-hardware.import-hardware')
            ->set('file', $file)
            ->call('importPreview')
            // Row 0 (PC-EXISTING) → status 'update', Row 1 (PC-NEW) → status 'create'
            // Override row 1 (PC-NEW) to skip
            ->set('previewData.1.selected_action', 'skip')
            ->call('confirmImport');

        // PC-EXISTING should have been updated (not skipped)
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-EXISTING', 'cpu' => 'New CPU']);
        // PC-NEW should NOT have been created (skip override)
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NEW']);
    }
}
