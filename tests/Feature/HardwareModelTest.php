<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

#[CoversClass(\App\Models\Hardware::class)]

class HardwareModelTest extends TestCase
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

    protected function createHardware(): Hardware
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);

        return Hardware::create([
            'n_code' => $nCode,
            'pc_name' => 'PC-001',
            'type' => 'Desktop',
            'os' => 'Windows 11',
            'cpu' => 'Intel i7',
            'ram' => '16GB',
            'hdd' => '512GB SSD',
        ]);
    }

    // --- Person relationship ---

    public function test_hardware_belongs_to_person_via_n_code(): void
    {
        $hardware = $this->createHardware();

        $this->assertNotNull($hardware->person);
        $this->assertEquals($hardware->n_code, $hardware->person->n_code);
    }

    // --- Audits relationship ---

    public function test_hardware_has_many_audits(): void
    {
        $hardware = $this->createHardware();

        // HardwareAuditObserver already creates a 'created' audit on creation
        $count = HardwareAudit::where('hardware_id', $hardware->id)->count();
        $this->assertGreaterThanOrEqual(1, $count);

        HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'action' => 'updated',
            'changes' => [['field' => 'ram', 'old' => '16GB', 'new' => '32GB']],
            'source' => 'web',
        ]);

        $this->assertDatabaseCount('hardware_audits', $count + 1);
    }

    public function test_hardware_histories_delegates_to_audits(): void
    {
        $hardware = $this->createHardware();

        $this->assertGreaterThanOrEqual(1, $hardware->histories->count());
    }

    // --- Persian normalization on save ---

    public function test_hardware_normalizes_persian_characters_on_save(): void
    {
        $hardware = $this->createHardware();

        // Yeh (ي) should be normalized to Yeh (ی), Kaf (ك) to Kaf (ک)
        $hardware->update(['pc_name' => 'كامپيوتر شخصي']);

        $hardware->refresh();
        $this->assertStringContainsString('ک', $hardware->pc_name);
    }

    public function test_hardware_normalizes_zwnj_on_save(): void
    {
        $hardware = $this->createHardware();

        // ZWNJ (U+200C) should be replaced with space
        $hardware->update(['comments' => 'کامپیوتر\u{200C}شخصی']);

        $hardware->refresh();
        $this->assertStringNotContainsString("\u{200C}", $hardware->comments);
    }

    public function test_hardware_does_not_normalize_empty_fields(): void
    {
        $hardware = $this->createHardware();

        $hardware->update(['comments' => 'test', 'vlan' => null]);

        $hardware->refresh();
        $this->assertEquals('test', $hardware->comments);
    }

    // --- flushStatsCache ---

    public function test_flushStatsCache_increments_hardware_stats_version(): void
    {
        Cache::put('hardware_stats_version', 0);

        Hardware::flushStatsCache();

        $this->assertGreaterThan(0, Cache::get('hardware_stats_version'));
    }

    public function test_flushStatsCache_increments_gis_version(): void
    {
        Cache::put('gis_version', 0);

        Hardware::flushStatsCache();

        $this->assertGreaterThan(0, Cache::get('gis_version'));
    }

    public function test_flushStatsCache_increments_maps_version(): void
    {
        Cache::put('maps_version', 0);

        Hardware::flushStatsCache();

        $this->assertGreaterThan(0, Cache::get('maps_version'));
    }

    public function test_flushStatsCache_increments_dashboard_version(): void
    {
        Cache::put('dashboard_version', 0);

        Hardware::flushStatsCache();

        $this->assertGreaterThan(0, Cache::get('dashboard_version'));
    }

    public function test_hardware_save_triggers_stats_cache_flush(): void
    {
        Cache::put('hardware_stats_version', 0);
        $hardware = $this->createHardware();

        $this->assertGreaterThan(0, Cache::get('hardware_stats_version'));
    }

    public function test_hardware_delete_triggers_stats_cache_flush(): void
    {
        $hardware = $this->createHardware();
        Cache::put('hardware_stats_version', 0);

        $hardware->delete();

        $this->assertGreaterThan(0, Cache::get('hardware_stats_version'));
    }

    // --- Fillable attributes ---

    public function test_hardware_allows_mass_assignment(): void
    {
        $hardware = $this->createHardware();

        $this->assertEquals('PC-001', $hardware->pc_name);
        $this->assertEquals('Desktop', $hardware->type);
        $this->assertEquals('Windows 11', $hardware->os);
        $this->assertEquals('Intel i7', $hardware->cpu);
        $this->assertEquals('16GB', $hardware->ram);
        $this->assertEquals('512GB SSD', $hardware->hdd);
    }

    // --- Boolean casts ---

    public function test_hardware_shutdown_is_cast_to_boolean(): void
    {
        $hardware = $this->createHardware();
        $hardware->update(['shutdown' => true]);
        $hardware->refresh();

        $this->assertIsBool($hardware->shutdown);
        $this->assertTrue($hardware->shutdown);
    }

    public function test_hardware_mark_is_cast_to_boolean(): void
    {
        $hardware = $this->createHardware();
        $hardware->update(['mark' => true]);
        $hardware->refresh();

        $this->assertIsBool($hardware->mark);
        $this->assertTrue($hardware->mark);
    }

    public function test_hardware_clean_at_is_cast_to_date(): void
    {
        $hardware = $this->createHardware();
        $hardware->update(['clean_at' => '2025-01-15']);
        $hardware->refresh();

        $this->assertNotNull($hardware->clean_at);
    }
}
