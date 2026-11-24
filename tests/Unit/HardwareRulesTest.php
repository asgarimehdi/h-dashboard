<?php

namespace Tests\Unit;

use App\Models\Hardware;
use App\Support\HardwareRules;
use Tests\TestCase;

/**
 * #1730: the hardware write rule set, in one place.
 *
 * The API and the Livewire form write the same 19 columns. Only the API
 * validated them; the web path validated three and wrote the other sixteen
 * unchecked. These tests pin the shared rule set and the two deliberate
 * asymmetries (`n_code` scope, `shutdown` on web create).
 */
covers(HardwareRules::class);

class HardwareRulesTest extends TestCase
{
    public function test_store_rules_cover_eighteen_fields_without_shutdown(): void
    {
        $rules = HardwareRules::apiRules();

        $this->assertCount(18, $rules);
        $this->assertArrayNotHasKey('shutdown', $rules);
    }

    public function test_update_and_web_rules_include_shutdown(): void
    {
        $this->assertSame('boolean', HardwareRules::apiRules(required: false, includeShutdown: true)['shutdown']);
        $this->assertSame('boolean', HardwareRules::webRules([])['shutdown']);
        $this->assertCount(19, HardwareRules::webRules([]));
    }

    public function test_every_writable_field_has_a_rule(): void
    {
        // Maintenance guard: a new fillable column without a rule fails here.
        $missing = array_values(array_diff((new Hardware)->getFillable(), array_keys(HardwareRules::webRules([]))));

        $this->assertSame([], $missing);
    }

    public function test_n_code_scope_is_web_only(): void
    {
        $this->assertSame('required|string|exists:persons,n_code', HardwareRules::apiRules()['n_code']);
        $this->assertIsArray(HardwareRules::webRules([1])['n_code']);
    }
}
