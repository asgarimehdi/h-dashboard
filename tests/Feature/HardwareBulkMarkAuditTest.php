<?php

namespace Tests\Feature;

use App\Events\HardwareUpdated;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Unit;
use App\Models\User;
use App\Support\HardwareRules;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1675: the web bulk mark must write the same `bulk_mark` audit row the API
 * path already writes.
 *
 * `bulkMark()` used a mass update, which fires no model events, and wrote no
 * audit at all — so every web bulk mark was invisible in the audit history
 * while the identical API call was audited. These tests pin the write, the
 * changed-rows-only filter (#1670), the actor/metadata, the suppression window
 * and the event dispatch parity.
 */
covers(Hardware::class);

class HardwareBulkMarkAuditTest extends TestCase
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
     * @return array{user: User, unit: Unit, hardware: list<Hardware>}
     */
    protected function createUserWithHardware(int $count = 2, string $prefix = 'PC-BulkMark'): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = [];
        for ($i = 1; $i <= $count; $i++) {
            $hardware[] = Hardware::create([
                'n_code' => $user->n_code,
                'pc_name' => "{$prefix}-{$i}",
                'type' => 'pc',
                'os' => 'Windows 10',
            ]);
        }

        return ['user' => $user, 'unit' => $unit, 'hardware' => $hardware];
    }

    /**
     * @param  list<int>  $ids
     */
    protected function webBulkMark(array $ids, bool $value = true): void
    {
        Livewire::test('hardware.index')
            ->set('selected', $ids)
            ->call('bulkMark', $value);
    }

    // ==================== The write ====================

    public function test_web_bulk_mark_writes_one_bulk_mark_audit_per_changed_device(): void
    {
        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $unmarked = $data['hardware'][0];

        $alreadyMarked = Hardware::create([
            'n_code' => $data['user']->n_code,
            'pc_name' => 'PC-BulkMark-ALREADY',
            'type' => 'pc',
            'mark' => true,
        ]);

        $this->webBulkMark([$unmarked->id, $alreadyMarked->id], true);

        $audits = HardwareAudit::query()
            ->where('hardware_id', $unmarked->id)
            ->where('action', 'bulk_mark')
            ->get();

        $this->assertCount(1, $audits);
        // `changes` is a jsonb column, so key order is not stable — assert the
        // field-level diff value by value.
        $changes = $audits->first()->changes;
        $this->assertCount(1, $changes);
        $this->assertSame('mark', $changes[0]['field']);
        $this->assertFalse($changes[0]['old']);
        $this->assertTrue($changes[0]['new']);
        $this->assertSame('bulk', $audits->first()->source);
        $this->assertSame($data['user']->id, $audits->first()->user_id);

        $this->assertSame(0, HardwareAudit::query()
            ->where('hardware_id', $alreadyMarked->id)
            ->where('action', 'bulk_mark')
            ->count(), 'a device already at the target value must not be audited');

        $this->assertTrue($unmarked->fresh()->mark);
        $this->assertTrue($alreadyMarked->fresh()->mark);
    }

    public function test_web_bulk_mark_audit_holds_actor_and_request_metadata(): void
    {
        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $id = $data['hardware'][0]->id;
        $this->webBulkMark([$id], true);

        $audit = HardwareAudit::query()
            ->where('hardware_id', $id)
            ->where('action', 'bulk_mark')
            ->firstOrFail();

        $this->assertSame($data['user']->id, $audit->user_id);
        $this->assertNotNull($audit->ip_address);
    }

    public function test_web_bulk_mark_false_audits_the_reverse_direction(): void
    {
        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $id = $data['hardware'][0]->id;

        $this->webBulkMark([$id], true);
        $this->webBulkMark([$id], false);

        $audits = HardwareAudit::query()
            ->where('hardware_id', $id)
            ->where('action', 'bulk_mark')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $audits);
        $changes = $audits->last()->changes;
        $this->assertCount(1, $changes);
        $this->assertSame('mark', $changes[0]['field']);
        $this->assertTrue($changes[0]['old']);
        $this->assertFalse($changes[0]['new']);
        $this->assertFalse($data['hardware'][0]->fresh()->mark);
    }

    public function test_suppress_audit_is_reset_after_a_web_bulk_mark(): void
    {
        $data = $this->createUserWithHardware(1);

        $this->assertFalse(Hardware::$suppressAudit);

        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);
        $this->webBulkMark([$data['hardware'][0]->id], true);

        // The window is set on the request attributes and must be removed in
        // the `finally` — a leaked flag would silently mute every later audit.
        $this->assertNull(request()->attributes->get('suppress_audit'));
        $this->assertFalse(Hardware::$suppressAudit);
    }

    public function test_web_bulk_mark_dispatches_the_hardware_updated_event(): void
    {
        Event::fake([HardwareUpdated::class]);

        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $id = $data['hardware'][0]->id;
        $this->webBulkMark([$id], true);

        Event::assertDispatched(
            HardwareUpdated::class,
            fn (HardwareUpdated $event): bool => $event->action === 'bulk_mark'
                && $event->hardware->id === $id
        );
    }

    // ==================== #1778: ids cap ====================

    public function test_web_bulk_mark_rejects_a_selection_over_the_cap(): void
    {
        $data = $this->createUserWithHardware(1);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $id = $data['hardware'][0]->id;

        $this->webBulkMark(array_fill(0, HardwareRules::MAX_BULK_IDS + 1, $id));

        $this->assertFalse(Hardware::findOrFail($id)->mark);
        $this->assertSame(0, HardwareAudit::query()->where('action', 'bulk_mark')->count());
    }
}
