<?php

use App\Http\Controllers\Api\HardwareController;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

covers(HardwareController::class);

class HardwareBulkAuditSuppressionTest extends TestCase
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

    protected function createUserWithHardware(): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->givePermissionTo('manage_hardware');

        $hardware1 = Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-1', 'type' => 'pc']);
        $hardware2 = Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-2', 'type' => 'laptop']);

        return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode, 'hardware' => [$hardware1, $hardware2]];
    }

    public function test_bulk_methods_use_try_finally_for_suppress_audit(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Api/HardwareController.php'));

        expect($src)->toContain('Hardware::$suppressAudit = true;');
        expect($src)->toContain('} finally {');
        expect(substr_count($src, 'Hardware::$suppressAudit = false;'))->toBe(2);
    }

    public function test_suppress_audit_is_false_after_bulk_mark(): void
    {
        expect(Hardware::$suppressAudit)->toBeFalse();

        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => true,
        ])->assertOk();

        expect(Hardware::$suppressAudit)->toBeFalse();
    }

    public function test_suppress_audit_is_false_after_bulk_delete(): void
    {
        expect(Hardware::$suppressAudit)->toBeFalse();

        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        $this->postJson('/api/hardware/bulk-delete', [
            'ids' => [$data['hardware'][0]->id],
        ])->assertOk();

        expect(Hardware::$suppressAudit)->toBeFalse();
    }

    public function test_audit_still_records_after_bulk_mark(): void
    {
        $data = $this->createUserWithHardware();
        $this->actingAs($data['user']);

        // Perform bulk mark
        $this->postJson('/api/hardware/bulk-mark', [
            'ids' => [$data['hardware'][0]->id],
            'mark' => true,
        ])->assertOk();

        // Verify the bulk_mark audit was recorded
        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $data['hardware'][0]->id,
            'action' => 'bulk_mark',
            'source' => 'bulk',
        ]);

        // Now create a new hardware — observer should fire (suppressAudit is false)
        $nCode = $data['n_code'];
        $newHw = Hardware::create(['n_code' => $nCode, 'pc_name' => 'PC-NEW', 'type' => 'pc']);

        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $newHw->id,
            'action' => 'created',
        ]);
    }
}
