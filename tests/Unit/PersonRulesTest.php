<?php

namespace Tests\Unit;

use App\Models\Person;
use App\Support\PersonRules;
use Tests\TestCase;

/**
 * #1745: the person write rule set, in one place.
 *
 * The API and the Livewire form write the same nine fields. The API validated
 * eight and never validated `phone`, so a submitted phone was silently
 * dropped. These tests pin the shared rule set and the three deliberate
 * asymmetries (n_code on web, no n_code on API update, phone optional).
 */
covers(PersonRules::class);

class PersonRulesTest extends TestCase
{
    public function test_store_rules_cover_nine_fields_including_phone(): void
    {
        $rules = PersonRules::apiRules();

        $this->assertCount(9, $rules);
        $this->assertSame('required|string|size:10|unique:persons,n_code', $rules['n_code']);
        $this->assertSame('nullable|string|max:20', $rules['phone']);
    }

    public function test_update_rules_carry_no_n_code_and_are_sometimes_required(): void
    {
        $rules = PersonRules::apiRules(required: false);

        $this->assertCount(8, $rules);
        $this->assertArrayNotHasKey('n_code', $rules); // #959 owns immutability
        $this->assertSame('sometimes|required|string|max:255', $rules['f_name']);
        $this->assertSame('nullable|string|max:20', $rules['phone']);
    }

    public function test_web_rules_keep_the_unique_n_code_spelling(): void
    {
        $this->assertCount(9, PersonRules::webRules());
        $this->assertSame(
            'required|string|size:10|unique:persons,n_code,NULL',
            PersonRules::webRules()['n_code']
        );
        $this->assertSame(
            'required|string|size:10|unique:persons,n_code,7',
            PersonRules::webRules(7)['n_code']
        );
    }

    public function test_rules_only_cover_fillable_columns(): void
    {
        $notFillable = array_values(array_diff(array_keys(PersonRules::webRules()), (new Person)->getFillable()));
        $this->assertSame([], $notFillable);

        // Deliberately unruled (neither write path touches them): see the class docblock.
        $unruled = array_values(array_diff((new Person)->getFillable(), array_keys(PersonRules::webRules())));
        $this->assertSame(['birth_date', 'hire_date', 'status'], $unruled);
    }
}
