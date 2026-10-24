<?php

namespace Tests\Unit;

use App\Models\Hardware;
use App\Support\HardwareAuditChange;
use Tests\TestCase;

/**
 * #927: the `hardware_audits.changes` contract, in one place.
 *
 * `toDiffList()` is the write-time guard. `HardwareAudit::insert()` bypasses
 * casts, so a cast on the model would never see a bulk insert — the guard has to
 * live where the payload is built, and these tests pin the coercion that a
 * future bulk writer will silently rely on.
 */
covers(HardwareAuditChange::class);

class HardwareAuditChangeTest extends TestCase
{
    // ==================== toDiffList (write-time guard) ====================

    public function test_null_stays_null(): void
    {
        $this->assertNull(HardwareAuditChange::toDiffList(null));
    }

    public function test_a_diff_list_is_passed_through(): void
    {
        $diff = [
            ['field' => 'pc_name', 'old' => 'PC-1', 'new' => 'deleted'],
        ];

        $this->assertSame($diff, HardwareAuditChange::toDiffList($diff));
    }

    public function test_an_attribute_map_is_coerced_into_a_diff_list(): void
    {
        // What the old bulkDelete() wrote.
        $this->assertSame(
            [
                ['field' => 'n_code', 'old' => '1234567890', 'new' => HardwareAuditChange::NO_VALUE],
                ['field' => 'pc_name', 'old' => 'PC-1', 'new' => HardwareAuditChange::NO_VALUE],
            ],
            HardwareAuditChange::toDiffList(['n_code' => '1234567890', 'pc_name' => 'PC-1'])
        );
    }

    public function test_a_bare_scalar_is_wrapped_into_a_list(): void
    {
        $result = HardwareAuditChange::toDiffList('PC-1');

        $this->assertIsArray($result);
        $this->assertTrue(array_is_list($result));
    }

    public function test_the_coercion_never_throws(): void
    {
        // A malformed audit payload must never be the reason a bulk delete fails.
        foreach ([[], 'text', 42, ['a', 'b'], [['no-field-key']], ['k' => ['nested' => 1]]] as $payload) {
            $this->assertIsArray(HardwareAuditChange::toDiffList($payload));
        }
    }

    // ==================== isDiffList ====================

    public function test_it_recognises_a_well_formed_diff_list(): void
    {
        $this->assertTrue(HardwareAuditChange::isDiffList([
            ['field' => 'cpu', 'old' => 'a', 'new' => 'b'],
        ]));
    }

    public function test_it_rejects_anything_else(): void
    {
        $this->assertFalse(HardwareAuditChange::isDiffList(null));
        $this->assertFalse(HardwareAuditChange::isDiffList([]));
        $this->assertFalse(HardwareAuditChange::isDiffList('text'));
        $this->assertFalse(HardwareAuditChange::isDiffList(['pc_name' => 'PC-1']));
        $this->assertFalse(HardwareAuditChange::isDiffList(['pc_name', 'ram']));
        $this->assertFalse(HardwareAuditChange::isDiffList([['old' => 'a', 'new' => 'b']]));
        $this->assertFalse(HardwareAuditChange::isDiffList([['field' => 'cpu'], 'scalar']));
    }

    // ==================== lines (read-time fallback) ====================

    public function test_a_diff_list_renders_as_field_old_and_new(): void
    {
        $this->assertSame(
            ['cpu: Intel i5 → Intel i7'],
            HardwareAuditChange::lines([['field' => 'cpu', 'old' => 'Intel i5', 'new' => 'Intel i7']])
        );
    }

    public function test_a_diff_entry_missing_a_side_falls_back_to_a_dash(): void
    {
        $this->assertSame(
            ['cpu: Intel i5 → —'],
            HardwareAuditChange::lines([['field' => 'cpu', 'old' => 'Intel i5']])
        );
    }

    public function test_a_map_renders_as_field_and_value(): void
    {
        $this->assertSame(
            ['pc_name: PC-1', 'shutdown: خیر'],
            HardwareAuditChange::lines(['pc_name' => 'PC-1', 'shutdown' => false])
        );
    }

    public function test_a_nested_value_cannot_through_the_renderer(): void
    {
        $lines = HardwareAuditChange::lines(['meta' => ['nested' => 1]]);

        $this->assertSame(['meta: {"nested":1}'], $lines);
    }

    public function test_a_bare_scalar_list_renders_as_its_values(): void
    {
        $this->assertSame(['pc_name', 'ram'], HardwareAuditChange::lines(['pc_name', 'ram']));
    }

    public function test_nothing_renderable_yields_no_lines(): void
    {
        $this->assertSame([], HardwareAuditChange::lines(null));
        $this->assertSame([], HardwareAuditChange::lines([]));
        $this->assertSame([], HardwareAuditChange::lines('text'));
    }

    // ==================== deletionSnapshot (the fixed writer) ====================

    public function test_the_deletion_snapshot_covers_the_audited_fields_only(): void
    {
        $hardware = new Hardware([
            'n_code' => '1234567890',
            'pc_name' => 'PC-1',
            'ram' => '8192',
        ]);
        $hardware->id = 999;
        $hardware->created_at = now();
        $hardware->updated_at = now();

        $snapshot = HardwareAuditChange::deletionSnapshot($hardware);
        $fields = array_column($snapshot, 'field');

        $this->assertSame(['n_code', 'pc_name', 'ram'], $fields);
        $this->assertNotContains('id', $fields);
        $this->assertNotContains('created_at', $fields);
        $this->assertNotContains('updated_at', $fields);

        // Every audited field the model actually holds belongs in the snapshot.
        foreach (HardwareAuditChange::AUDITED_FIELDS as $field) {
            $value = $hardware->getAttribute($field);
            if ($value !== null && $value !== '') {
                $this->assertContains($field, $fields, "field {$field} is audit-worthy but missing");
            }
        }
    }

    public function test_the_deletion_snapshot_marks_the_value_as_going_away(): void
    {
        $hardware = new Hardware(['pc_name' => 'PC-1']);

        $snapshot = HardwareAuditChange::deletionSnapshot($hardware);

        $this->assertSame(
            ['field' => 'pc_name', 'old' => 'PC-1', 'new' => HardwareAuditChange::DELETED_VALUE],
            $snapshot[0]
        );
    }

    public function test_the_deletion_snapshot_skips_null_and_empty_values(): void
    {
        $snapshot = HardwareAuditChange::deletionSnapshot(new Hardware([
            'pc_name' => 'PC-1',
            'cpu' => '',
            'ram' => null,
        ]));

        $this->assertSame(['pc_name'], array_column($snapshot, 'field'));
    }

    public function test_created_and_bulk_delete_agree_on_the_audited_fields(): void
    {
        // The observer's `created` writer and the `bulk_delete` snapshot must
        // never drift apart, or the two stop describing the same device.
        $hardware = new Hardware([
            'n_code' => '1234567890',
            'pc_name' => 'PC-1',
            'shutdown' => true,
        ]);

        $snapshot = HardwareAuditChange::deletionSnapshot($hardware);
        $created = collect(HardwareAuditChange::AUDITED_FIELDS)
            ->filter(fn (string $field): bool => (bool) $hardware->getAttribute($field));

        $this->assertSame($created->values()->all(), array_column($snapshot, 'field'));
    }
}
