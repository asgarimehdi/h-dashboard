<?php

namespace Tests\Unit;

use App\Rules\IranianNationalCode;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * #1243 — the Iranian national-code checksum, one implementation shared by the
 * patient registry and the case form (#1244).
 *
 * Data warning (spec §3.4 is stale): `0012345678` does NOT carry a valid
 * checksum — its first nine digits sum to 112, `112 % 11 = 2`, so the check
 * digit must be `9`, not `8`. It is pinned here as an INVALID case on purpose,
 * and the two codes the plan hands us (`0499370899`, `0013542419`) are the
 * valid fixtures.
 */
covers(IranianNationalCode::class);

class IranianNationalCodeTest extends TestCase
{
    // ==================== valid ====================

    public function test_a_checksum_valid_code_is_accepted(): void
    {
        $this->assertTrue(IranianNationalCode::isValid('0499370899'));
        $this->assertTrue(IranianNationalCode::isValid('0013542419'));
    }

    public function test_persian_digits_are_folded_before_the_checksum(): void
    {
        $this->assertTrue(IranianNationalCode::isValid('۰۴۹۹۳۷۰۸۹۹'));
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertTrue(IranianNationalCode::isValid('  0499370899  '));
    }

    // ==================== invalid ====================

    public function test_the_stale_spec_sample_is_rejected(): void
    {
        // sum = 112 → 112 % 11 = 2 → check digit must be 9, the sample says 8.
        $this->assertFalse(IranianNationalCode::isValid('0012345678'));
    }

    public function test_a_code_with_ten_identical_digits_is_rejected(): void
    {
        $this->assertFalse(IranianNationalCode::isValid('1111111111'));
        $this->assertFalse(IranianNationalCode::isValid('0000000000'));
    }

    public function test_wrong_lengths_are_rejected(): void
    {
        $this->assertFalse(IranianNationalCode::isValid('049937089'));   // 9
        $this->assertFalse(IranianNationalCode::isValid('04993708990')); // 11
    }

    public function test_non_digits_are_rejected(): void
    {
        $this->assertFalse(IranianNationalCode::isValid('049937089a'));
        $this->assertFalse(IranianNationalCode::isValid('abcdefghij'));
    }

    public function test_an_empty_code_is_rejected(): void
    {
        $this->assertFalse(IranianNationalCode::isValid(''));
        $this->assertFalse(IranianNationalCode::isValid('   '));
    }

    // ==================== validation rule ====================

    public function test_the_rule_fails_with_the_shared_message(): void
    {
        $validator = Validator::make(
            ['kod_melli' => '0012345678'],
            ['kod_melli' => [new IranianNationalCode]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('کد ملی معتبر نیست.', $validator->errors()->first('kod_melli'));
    }

    public function test_the_rule_passes_a_valid_code(): void
    {
        $validator = Validator::make(
            ['kod_melli' => '0499370899'],
            ['kod_melli' => [new IranianNationalCode]],
        );

        $this->assertFalse($validator->fails());
    }
}
