<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\User;
use App\Observers\HardwareAuditObserver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1984: the bulk-mark writers store the raw boolean in the audit `changes`
 * column (the observer/import writers store escaped display strings). A
 * `rollback` row created from a bulk-mark therefore carries a raw
 * `old=false`, which the web history modal could never restore: the string
 * parser turned `false` into `''` and `valueRoundTrips` rejected it. The
 * modal also hid the restore button for `bulk_mark` rows even though the API
 * rolls them back.
 *
 * Assertions are on the database (per AGENTS.md), never on component state:
 * this component reports failures through MaryUI's Toast trait, which does
 * not populate a Livewire error bag.
 */
class HardwareAuditRollbackBooleanTest extends TestCase
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
     * @return array{0: User, 1: Hardware}
     */
    private function makeUserAndHardware(bool $mark): array
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = Hardware::factory()->create([
            'n_code' => $user->n_code,
            'mark' => $mark,
        ]);

        return [$user, $hardware];
    }

    private function bearerToken($user): string
    {
        return 'Bearer '.$user->createToken('test')->plainTextToken;
    }

    private function bulkMark(Hardware $hardware, string $token, bool $mark): void
    {
        $this->withHeaders(['Authorization' => $token])
            ->postJson('/api/hardware/bulk-mark', [
                'ids' => [$hardware->id],
                'mark' => $mark,
            ])
            ->assertOk();
    }

    private function apiRollback(Hardware $hardware, HardwareAudit $audit, string $token): void
    {
        $this->withHeaders(['Authorization' => $token])
            ->postJson("/api/hardware/{$hardware->id}/audits/{$audit->id}/rollback", [
                'field' => 'mark',
            ])
            ->assertOk();
    }

    private function latestAudit(Hardware $hardware, string $action): HardwareAudit
    {
        return HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', $action)
            ->latest('id')
            ->firstOrFail();
    }

    private function rollbackCount(Hardware $hardware): int
    {
        return HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'rollback')
            ->count();
    }

    public function test_web_rollback_restores_raw_false_from_a_rollback_row(): void
    {
        [$user, $hardware] = $this->makeUserAndHardware(true);
        $token = $this->bearerToken($user);

        // bulk-mark turns mark off: audit row stores raw old=true, new=false.
        $this->bulkMark($hardware, $token, false);
        $bulk = $this->latestAudit($hardware, 'bulk_mark');
        $this->assertTrue($bulk->changes[0]['old']);
        $this->assertFalse($bulk->changes[0]['new']);

        // API rollback turns mark back on and logs a rollback row whose raw
        // `old` is `false` (it swaps the bulk row's new/old).
        $this->apiRollback($hardware, $bulk, $token);
        $this->assertTrue($hardware->fresh()->mark);
        $rollback = $this->latestAudit($hardware, 'rollback');
        $this->assertFalse($rollback->changes[0]['old']);
        $this->assertTrue($rollback->changes[0]['new']);

        $rollbacksBefore = $this->rollbackCount($hardware);

        Livewire::actingAs($user)->test('hardware.index')
            ->call('loadHistory', $hardware->id)
            ->call('rollbackHistoryField', $rollback->id, 'mark');

        // Rolling back the rollback row writes its own `old=false`.
        $this->assertFalse($hardware->fresh()->mark);
        $this->assertSame($rollbacksBefore + 1, $this->rollbackCount($hardware));
    }

    public function test_web_rollback_still_restores_raw_true(): void
    {
        [$user, $hardware] = $this->makeUserAndHardware(false);

        // A rollback row that carries a raw `old=true` is written by the same
        // observer the API's rollback uses (recordRollbackAudit). It is created
        // directly here because the API's own rollback of a raw `old=false`
        // bulk row 500s on Postgres (`parseValueForRestore(string)` coerces
        // `false` to '') — a separate, out-of-scope defect (#1984 scope).
        app(HardwareAuditObserver::class)->recordRollbackAudit($hardware, [
            ['field' => 'mark', 'old' => true, 'new' => false],
        ], $user->id);

        $rollback = $this->latestAudit($hardware, 'rollback');
        $this->assertTrue($rollback->changes[0]['old']);

        Livewire::actingAs($user)->test('hardware.index')
            ->call('loadHistory', $hardware->id)
            ->call('rollbackHistoryField', $rollback->id, 'mark');

        $this->assertTrue($hardware->fresh()->mark);
    }

    public function test_bulk_mark_row_offers_and_performs_web_rollback(): void
    {
        [$user, $hardware] = $this->makeUserAndHardware(true);
        $token = $this->bearerToken($user);

        $this->bulkMark($hardware, $token, false);
        $bulk = $this->latestAudit($hardware, 'bulk_mark');
        $this->assertFalse($hardware->fresh()->mark);

        $rollbacksBefore = $this->rollbackCount($hardware);

        Livewire::actingAs($user)->test('hardware.index')
            ->call('loadHistory', $hardware->id)
            ->assertSeeHtml("rollbackHistoryField({$bulk->id}, 'mark')")
            ->call('rollbackHistoryField', $bulk->id, 'mark');

        $this->assertTrue($hardware->fresh()->mark);
        $this->assertSame($rollbacksBefore + 1, $this->rollbackCount($hardware));
    }

    public function test_web_rollback_still_restores_string_display_values(): void
    {
        [$user, $hardware] = $this->makeUserAndHardware(true);

        // Observer path: stores escaped display strings ('بله' / 'خیر').
        $hardware->update(['mark' => false]);
        $updated = $this->latestAudit($hardware, 'updated');
        $this->assertSame('بله', $updated->changes[0]['old']);
        $this->assertSame('خیر', $updated->changes[0]['new']);

        Livewire::actingAs($user)->test('hardware.index')
            ->call('loadHistory', $hardware->id)
            ->call('rollbackHistoryField', $updated->id, 'mark');

        $this->assertTrue($hardware->fresh()->mark);
    }
}
