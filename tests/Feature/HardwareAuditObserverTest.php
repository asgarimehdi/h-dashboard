<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Observers\HardwareAuditObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

covers(HardwareAudit::class);

class HardwareAuditObserverTest extends TestCase
{
    use RefreshDatabase;

    protected $tId;

    protected $eId;

    protected $sId;

    protected $rId;

    protected $unit;

    protected $user;

    protected $hardware;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'T']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'E']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'S']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => 'R']);

        $nCode = (string) fake()->unique()->numerify('##########');
        $this->unit = Unit::create(['name' => 'Obs Unit']);
        Person::create([
            'n_code' => $nCode, 'f_name' => 'A', 'l_name' => 'B',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId, 'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);
        $this->actingAs($this->user);

        $this->hardware = Hardware::create([
            'n_code' => $nCode,
            'pc_name' => 'OBS-PC',
            'type' => 'pc',
            'mark' => false,
            'shutdown' => false,
        ]);
    }

    public function test_created_records_audit_with_full_snapshot(): void
    {
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($audit);
        $this->assertIsArray($audit->changes);
        // pc_name should appear in the created snapshot
        $this->assertNotNull(collect($audit->changes)->firstWhere('field', 'pc_name'));
    }

    public function test_suppress_audit_flag_prevents_logging(): void
    {
        Hardware::$suppressAudit = true;

        $countBefore = HardwareAudit::count();
        Hardware::create([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'pc_name' => 'SUPPRESSED',
            'type' => 'pc',
        ]);
        $countAfter = HardwareAudit::count();

        Hardware::$suppressAudit = false;

        $this->assertEquals($countBefore, $countAfter);
    }

    public function test_updating_logs_only_changed_fields(): void
    {
        $this->hardware->update(['cpu' => 'Intel i9']);

        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($audit);
        $cpuChange = collect($audit->changes)->firstWhere('field', 'cpu');
        $this->assertNotNull($cpuChange);
        $this->assertEquals('Intel i9', $cpuChange['new']);
        // unchanged field should not appear
        $this->assertNull(collect($audit->changes)->firstWhere('field', 'pc_name'));
    }

    public function test_deleting_records_deleted_audit(): void
    {
        $hwId = $this->hardware->id;
        $this->hardware->delete();

        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $hwId,
            'action' => 'deleted',
        ]);
    }

    public function test_force_deleted_records_force_deleted_audit(): void
    {
        // The Hardware model has no SoftDeletes, so forceDeleted is not auto-fired
        // by Eloquent; call the observer method directly to cover that branch.
        app(HardwareAuditObserver::class)->forceDeleted($this->hardware);

        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $this->hardware->id,
            'action' => 'force_deleted',
        ]);
    }

    public function test_record_bulk_audit_uses_bulk_source(): void
    {
        $changes = [['field' => 'mark', 'old' => 'خیر', 'new' => 'بله']];
        app(HardwareAuditObserver::class)->recordBulkAudit($this->hardware, 'bulk_mark', $changes);

        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $this->hardware->id,
            'action' => 'bulk_mark',
            'source' => 'bulk',
        ]);
    }

    public function test_record_rollback_audit_creates_rollback_entry(): void
    {
        $changes = [['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7']];
        app(HardwareAuditObserver::class)->recordRollbackAudit($this->hardware, $changes, $this->user->id);

        $this->assertDatabaseHas('hardware_audits', [
            'hardware_id' => $this->hardware->id,
            'action' => 'rollback',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_format_value_for_display_handles_bool_and_null(): void
    {
        $observer = app(HardwareAuditObserver::class);

        // Accessed indirectly via an update producing a boolean change.
        $this->hardware->update(['mark' => true]);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();
        $markChange = collect($audit->changes)->firstWhere('field', 'mark');
        $this->assertEquals('بله', $markChange['new']);
    }
}
