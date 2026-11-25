<?php

namespace Tests\Unit;

use App\Imports\HardwareImport;
use App\Imports\PersonImport;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * #1813: pin the public API of both importers.
 *
 * The shared-pipeline extraction must not add, remove or rename a public
 * method. `getMethods(IS_PUBLIC)` includes inherited methods, so moving
 * `collection()` / `getImportResults()` / `getCsvSettings()` onto a base class
 * is visible here as "still present", while an accidental extra public helper
 * fails the test.
 */
covers(HardwareImport::class, PersonImport::class);

class ImportPublicApiTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function publicMethods(string $class): array
    {
        $methods = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        return $methods;
    }

    public function test_hardware_import_public_api_is_stable(): void
    {
        $this->assertSame([
            '__construct',
            'collection',
            'getCsvSettings',
            'getImportResults',
            'setAccessibleUnitIds',
            'setAuditContext',
            'setCompareKey',
            'setSelectedActions',
        ], $this->publicMethods(HardwareImport::class));

        $interfaces = class_implements(HardwareImport::class);
        $this->assertArrayHasKey(ToCollection::class, $interfaces);
        $this->assertArrayHasKey(WithCustomCsvSettings::class, $interfaces);
        $this->assertArrayHasKey(WithHeadingRow::class, $interfaces);
    }

    public function test_person_import_public_api_is_stable(): void
    {
        $this->assertSame([
            '__construct',
            'charMap',
            'collection',
            'digitMap',
            'escapeLikeWildcards',
            'foldSeparatorsSql',
            'foldedTerm',
            'getCsvSettings',
            'getImportResults',
            'normalize',
            'normalizeForQuery',
            'normalizeForSearch',
            'setAccessibleUnitIds',
            'setSelectedActions',
        ], $this->publicMethods(PersonImport::class));

        $interfaces = class_implements(PersonImport::class);
        $this->assertArrayHasKey(ToCollection::class, $interfaces);
        $this->assertArrayHasKey(WithCustomCsvSettings::class, $interfaces);
        $this->assertArrayHasKey(WithHeadingRow::class, $interfaces);
    }
}
