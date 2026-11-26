<?php

namespace Tests\Unit;

use App\Imports\HardwareImport;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * #1034: the CSV null token in normalizeForComparison() must match the one the
 * other two guards in HardwareImport already use.
 *
 * `clean()` and `parseBoolean()` screen for a runtime `\N` (source `'\\N'`), but
 * normalizeForComparison() screened for a five-backslash token — a literal that
 * can never match the real CSV null. Any raw `\N` that reached the comparison
 * therefore fell through to `trim((string) $value)` and read as the two
 * characters `\` and `N` instead of a null-like value. These tests pin the
 * shared token so the three sites cannot drift apart again.
 *
 * #1851: "no value" (null / '' / '\N') normalizes to the empty string `''`,
 * never to `'0'`. A text column (port, vlan, switch, comments, …) may
 * legitimately store the literal string "0", so collapsing it into the empty
 * sentinel made an `empty ↔ "0"` change invisible to the change detector. The
 * `0`/`1` collapse is reserved for real booleans (shutdown, mark).
 */
covers(HardwareImport::class);

class HardwareImportNormalizationTest extends TestCase
{
    /**
     * normalizeForComparison() is protected and reads no instance state, so the
     * class is built without its constructor (which resolves AccessService) to
     * keep this a true unit test.
     */
    private function normalize(mixed $value): ?string
    {
        $import = (new ReflectionClass(HardwareImport::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($import, 'normalizeForComparison');
        $method->setAccessible(true);

        return $method->invoke($import, $value);
    }

    public function test_csv_null_token_is_empty(): void
    {
        $this->assertSame('', $this->normalize('\\N'));
    }

    public function test_null_is_empty(): void
    {
        $this->assertSame('', $this->normalize(null));
    }

    public function test_empty_string_is_empty(): void
    {
        $this->assertSame('', $this->normalize(''));
    }

    public function test_literal_zero_stays_distinct_from_empty(): void
    {
        $this->assertSame('0', $this->normalize('0'));
        $this->assertSame('0', $this->normalize(0));
        $this->assertNotSame($this->normalize(''), $this->normalize('0'));
    }

    public function test_boolean_false_is_zero_and_true_is_one(): void
    {
        $this->assertSame('0', $this->normalize(false));
        $this->assertSame('1', $this->normalize(true));
    }

    public function test_boolean_true_is_preserved(): void
    {
        $this->assertSame('1', $this->normalize('1'));
    }
}
