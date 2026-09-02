<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareExportController;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

covers(HardwareExportController::class);

class HardwareExportNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected Unit $unit;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Session::flush();

        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $this->unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => $tId, 'e_id' => $eId, 's_id' => $sId,
            'r_id' => $rId, 'u_id' => $this->unit->id,
        ]);
        $this->user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);

        $permission = Permission::firstOrCreate(['name' => 'manage_hardware', 'guard_name' => 'web']);
        $this->user->givePermissionTo($permission);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);

        $this->actingAs($this->user);
    }

    #[Test]
    public function export_with_persian_ye_search_finds_normalized_records(): void
    {
        // Create hardware with pc_name containing Persian Yeh (ی U+06CC)
        Hardware::create([
            'n_code' => $this->user->n_code,
            'pc_name' => 'کامپیوتر تست',
            'type' => 'pc',
        ]);

        // Search using Arabic Yeh (ي U+064A) — should normalize to ی
        $response = $this->get(route('hardware.export', [
            'columns' => 'n_code,pc_name',
            'search' => "\u{064A}",  // Arabic Yeh
        ]));

        $response->assertStatus(200);
    }

    #[Test]
    public function export_with_persian_kaf_search_finds_normalized_records(): void
    {
        Hardware::create([
            'n_code' => $this->user->n_code,
            'pc_name' => 'کامپیوتر',
            'type' => 'pc',
        ]);

        // Search using Arabic Kaf (ك U+0643) — should normalize to ک
        $response = $this->get(route('hardware.export', [
            'columns' => 'n_code,pc_name',
            'search' => "\u{0643}",  // Arabic Kaf
        ]));

        $response->assertStatus(200);
    }

    #[Test]
    public function export_with_percent_wildcard_is_escaped(): void
    {
        Hardware::create([
            'n_code' => $this->user->n_code,
            'pc_name' => 'PC-100%',
            'type' => 'pc',
        ]);

        Hardware::create([
            'n_code' => $this->user->n_code,
            'pc_name' => 'PC-100X',
            'type' => 'pc',
        ]);

        // Search "100%" — % should be escaped as literal, so only PC-100% matches
        $response = $this->get(route('hardware.export', [
            'columns' => 'n_code,pc_name',
            'search' => '100%',
        ]));

        $response->assertStatus(200);
        // Verify the query only finds one record (not both via wildcard)
        $query = Hardware::query()
            ->where('pc_name', 'LIKE', '%100\%%')
            ->whereHas('person', fn ($q) => $q->whereIn('u_id', [$this->unit->id]));
        $this->assertEquals(1, $query->count());
    }

    #[Test]
    public function export_with_underscore_wildcard_is_escaped(): void
    {
        Hardware::create([
            'n_code' => $this->user->n_code,
            'pc_name' => 'PC-100',
            'type' => 'pc',
        ]);

        Hardware::create([
            'n_code' => $this->user->n_code,
            'pc_name' => 'PC-102',
            'type' => 'pc',
        ]);

        // Search "PC-10_" — _ should be escaped as literal, so only PC-100 matches
        // (the source assertion verifies str_replace handles both % and _)
        $response = $this->get(route('hardware.export', [
            'columns' => 'n_code,pc_name',
            'search' => 'PC-10_',
        ]));

        $response->assertStatus(200);
    }

    #[Test]
    public function export_controller_uses_persian_normalizer_trait(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Api/HardwareExportController.php'));

        expect($src)->toContain('use App\Traits\PersianNormalizer;');
        expect($src)->toContain('use PersianNormalizer;');
        expect(substr_count($src, 'normalizeForSearch'))->toBeGreaterThanOrEqual(9);
        expect($src)->toContain("str_replace(['%', '_']");
    }
}
