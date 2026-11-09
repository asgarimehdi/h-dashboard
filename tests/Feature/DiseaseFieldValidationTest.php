<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Models\DiseaseFieldDef;
use App\Services\Disease\DiseaseFieldValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1241 Task 2 — server-side validation of the dynamic `fields` payload.
 *
 * The field definitions are the only contract: the client is never trusted,
 * unknown keys are rejected, `0` is a value (not "empty"), and every error
 * carries the `fields.{field_key}` key so phase 3 can render it beside the
 * input.
 */
class DiseaseFieldValidationTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Disease $disease;

    private DiseaseFieldValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disease = Disease::factory()->create();
        $this->validator = new DiseaseFieldValidator;

        $this->define('sys', 'number', true, 1, null, 'قند خون');
        $this->define('dia', 'number', false, 2, null, 'فشار خون');
        $this->define('type', 'select', true, 3, ['β/thal', 'δβ', 'α/thal'], 'نوع');
        $this->define('onset', 'date', false, 4, null, 'تاریخ شروع');
        $this->define('note', 'text', true, 5, null, 'یادداشت');
    }

    /** @param array<int, string>|null $options */
    private function define(string $key, string $type, bool $required, int $sort, ?array $options, string $label): DiseaseFieldDef
    {
        return DiseaseFieldDef::create([
            'disease_id' => $this->disease->id,
            'field_key' => $key,
            'label_fa' => $label,
            'type' => $type,
            'options' => $options,
            'required' => $required,
            'sort' => $sort,
        ]);
    }

    /** @return Collection<int, DiseaseFieldDef> */
    private function defs(): Collection
    {
        return DiseaseFieldDef::query()->where('disease_id', $this->disease->id)->orderBy('sort')->get();
    }

    /** @param array<string, mixed> $overrides */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'sys' => '140',
            'type' => 'δβ',
            'note' => 'یادداشت بالینی',
        ], $overrides);
    }

    /** @param array<string, mixed> $fields */
    private function assertFieldError(array $fields, string $key): void
    {
        try {
            $this->validator->validate($this->defs(), $fields);
            $this->fail("Expected ValidationException for {$key}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), "Missing error key {$key}");
        }
    }

    public function test_required_number_missing_is_rejected(): void
    {
        $fields = $this->valid();
        unset($fields['sys']);

        $this->assertFieldError($fields, 'fields.sys');
    }

    public function test_number_must_be_numeric(): void
    {
        $this->assertFieldError($this->valid(['sys' => 'abc']), 'fields.sys');
    }

    public function test_number_is_normalized_to_int(): void
    {
        $result = $this->validator->validate($this->defs(), $this->valid(['sys' => '140']));

        $this->assertSame(140, $result['sys']);
    }

    public function test_zero_is_not_empty(): void
    {
        $result = $this->validator->validate($this->defs(), $this->valid(['sys' => '0']));

        $this->assertSame(0, $result['sys']);
    }

    public function test_empty_optional_field_is_dropped_without_error(): void
    {
        $result = $this->validator->validate($this->defs(), $this->valid(['dia' => '']));

        $this->assertArrayNotHasKey('dia', $result);
    }

    public function test_select_accepts_a_defined_option(): void
    {
        $result = $this->validator->validate($this->defs(), $this->valid(['type' => 'δβ']));

        $this->assertSame('δβ', $result['type']);
    }

    public function test_select_rejects_an_undefined_option(): void
    {
        $this->assertFieldError($this->valid(['type' => 'β']), 'fields.type');
    }

    public function test_date_rejects_an_impossible_day(): void
    {
        $this->assertFieldError($this->valid(['onset' => '2026-02-30']), 'fields.onset');
    }

    public function test_date_keeps_the_y_m_d_value(): void
    {
        $result = $this->validator->validate($this->defs(), $this->valid(['onset' => '2026-03-01']));

        $this->assertSame('2026-03-01', $result['onset']);
    }

    public function test_text_at_the_limit_is_accepted(): void
    {
        $text = str_repeat('a', DiseaseFieldValidator::MAX_TEXT_LENGTH);

        $result = $this->validator->validate($this->defs(), $this->valid(['note' => $text]));

        $this->assertSame($text, $result['note']);
    }

    public function test_text_over_the_limit_is_rejected(): void
    {
        $text = str_repeat('a', DiseaseFieldValidator::MAX_TEXT_LENGTH + 1);

        $this->assertFieldError($this->valid(['note' => $text]), 'fields.note');
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->assertFieldError($this->valid(['hacked' => 1]), 'fields.hacked');
    }

    public function test_required_text_of_only_spaces_is_rejected(): void
    {
        $this->assertFieldError($this->valid(['note' => '   ']), 'fields.note');
    }
}
