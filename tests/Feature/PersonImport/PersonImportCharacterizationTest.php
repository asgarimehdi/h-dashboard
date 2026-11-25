<?php

namespace Tests\Feature\PersonImport;

use App\Imports\PersonImport;
use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Behavioural characterisation for PersonImport (#1813).
 *
 * These tests were written against the pre-extraction implementation and must
 * stay green, byte-for-byte unchanged, after PersonImport moves onto
 * AbstractImportPipeline. They pin the preview/confirm result shape, the
 * Persian folding comparison (#1769) and the cache-bump contract (#1351).
 */
covers(PersonImport::class);

class PersonImportCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private Semat $semat;

    private Tahsil $tahsil;

    private Estekhdam $estekhdam;

    private Radif $radif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->unit = Unit::create(['name' => 'واحد تست']);
        $this->semat = Semat::create(['name' => 'تکنسین']);
        $this->tahsil = Tahsil::create(['name' => 'لیسانس']);
        $this->estekhdam = Estekhdam::create(['name' => 'رسمی']);
        $this->radif = Radif::create(['name' => 'ردیف 1']);
    }

    private function header(): string
    {
        return "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\tphone";
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $content = $this->header()."\n";
        foreach ($rows as $row) {
            $content .= implode("\t", $row)."\n";
        }

        $file = tempnam(sys_get_temp_dir(), 'person_import_char_').'.csv';
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * @return list<int>
     */
    private function fullIds(): array
    {
        return [
            $this->tahsil->id,
            $this->estekhdam->id,
            $this->semat->id,
            $this->radif->id,
            $this->unit->id,
        ];
    }

    /**
     * @return list<string>
     */
    private function personRow(string $nCode, string $fName, string $lName, string $phone = ''): array
    {
        return [
            $nCode,
            $fName,
            $lName,
            ...$this->fullIds(),
            $phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mappedRow(?string $nCode, ?string $fName, ?string $lName, ?string $phone): array
    {
        return [
            'n_code' => $nCode,
            'f_name' => $fName,
            'l_name' => $lName,
            't_id' => $this->tahsil->id,
            'e_id' => $this->estekhdam->id,
            'r_id' => $this->radif->id,
            's_id' => $this->semat->id,
            'u_id' => $this->unit->id,
            'phone' => $phone,
        ];
    }

    private function createExistingPerson(
        string $nCode,
        string $fName,
        string $lName,
        ?string $phone = null,
    ): Person {
        return Person::create([
            'n_code' => $nCode,
            'f_name' => $fName,
            'l_name' => $lName,
            't_id' => $this->tahsil->id,
            'e_id' => $this->estekhdam->id,
            's_id' => $this->semat->id,
            'r_id' => $this->radif->id,
            'u_id' => $this->unit->id,
            'phone' => $phone,
        ]);
    }

    private function scopedImport(): PersonImport
    {
        $import = new PersonImport;
        $import->setAccessibleUnitIds([$this->unit->id]);

        return $import;
    }

    public function test_golden_preview_and_confirm_snapshot(): void
    {
        $this->createExistingPerson('1234567890', 'احمد', 'محمدی');
        $this->createExistingPerson('2222222222', 'علی', 'رضایی', '09120000002');

        $file = $this->csv([
            $this->personRow('1111111111', 'علی', 'رضایی', '09120000001'),
            $this->personRow('1234567890', 'اکبر', 'رضایی', '09120000002'),
            $this->personRow('2222222222', 'علی', 'رضایی', '09120000002'),
            $this->personRow('', 'علی', 'رضایی'),
        ]);

        $createData = $this->mappedRow('1111111111', 'علی', 'رضایی', '09120000001');
        $updateData = $this->mappedRow('1234567890', 'اکبر', 'رضایی', '09120000002');
        $unchangedData = $this->mappedRow('2222222222', 'علی', 'رضایی', '09120000002');
        $errorData = $this->mappedRow(null, 'علی', 'رضایی', null);

        $preview = [
            [
                'row' => 2,
                'status' => 'create',
                'n_code' => '1111111111',
                'person' => 'علی رضایی',
                'data' => $createData,
            ],
            [
                'row' => 3,
                'status' => 'update',
                'n_code' => '1234567890',
                'match_key' => 'n_code',
                'changes' => [
                    'f_name' => ['old' => 'احمد', 'new' => 'اکبر'],
                    'l_name' => ['old' => 'محمدی', 'new' => 'رضایی'],
                    'phone' => ['old' => null, 'new' => '09120000002'],
                ],
                'person' => 'احمد محمدی',
                'data' => $updateData,
            ],
            [
                'row' => 4,
                'status' => 'unchanged',
                'n_code' => '2222222222',
                'match_key' => 'n_code',
                'message' => 'بدون تغییر',
                'person' => 'علی رضایی',
                'data' => $unchangedData,
            ],
            [
                'row' => 5,
                'status' => 'error',
                'message' => 'فیلدهای اجباری n_code، f_name و l_name خالی هستند',
                'data' => $errorData,
            ],
        ];

        $previewImport = $this->scopedImport();
        Excel::import($previewImport, $file);

        $this->assertSame([
            'created' => 1,
            'updated' => 1,
            'skipped' => 1,
            'errors' => [[
                'row' => 5,
                'error' => 'فیلدهای اجباری n_code، f_name و l_name خالی هستند',
                'data' => $errorData,
            ]],
            'changes' => [],
            'preview' => $preview,
        ], $previewImport->getImportResults());

        $confirmImport = $this->scopedImport();
        $confirmImport->setSelectedActions([
            'row_2' => 'create',
            'row_3' => 'update',
            'row_4' => 'skip',
            'row_5' => 'create',
        ]);
        Excel::import($confirmImport, $file);

        $this->assertSame([
            'created' => 1,
            'updated' => 1,
            'skipped' => 2,
            'errors' => [[
                'row' => 5,
                'error' => 'فیلدهای اجباری n_code، f_name و l_name خالی هستند',
                'data' => $errorData,
            ]],
            'changes' => [[
                'row' => 3,
                'n_code' => '1234567890',
                'match_key' => 'n_code',
                'changes' => [
                    'f_name' => ['old' => 'احمد', 'new' => 'اکبر'],
                    'l_name' => ['old' => 'محمدی', 'new' => 'رضایی'],
                    'phone' => ['old' => null, 'new' => '09120000002'],
                ],
            ]],
            'preview' => $preview,
        ], $confirmImport->getImportResults());

        $this->assertDatabaseHas('persons', [
            'n_code' => '1111111111',
            'f_name' => 'علی',
            'l_name' => 'رضایی',
            'phone' => '09120000001',
        ]);
        $this->assertDatabaseHas('persons', [
            'n_code' => '1234567890',
            'f_name' => 'اکبر',
            'l_name' => 'رضایی',
            'phone' => '09120000002',
        ]);

        @unlink($file);
    }

    public function test_duplicate_new_rows_dedupe_but_still_bump_every_cache_namespace(): void
    {
        $file = $this->csv([
            $this->personRow('3333333333', 'علی', 'رضایی'),
            $this->personRow('3333333333', 'علی', 'رضایی'),
        ]);

        $namespaces = ['hr_stats', 'dashboard', 'report_persons', 'maps', 'gis', 'unit_hierarchy'];
        $before = [];
        foreach ($namespaces as $namespace) {
            $before[$namespace] = Cache::get("{$namespace}_version", 0);
        }

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create', 'row_3' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(1, $results['created']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame(1, Person::where('n_code', '3333333333')->count());

        foreach ($namespaces as $namespace) {
            $this->assertGreaterThan(
                $before[$namespace],
                Cache::get("{$namespace}_version", 0),
                "the bulk import must bump {$namespace}",
            );
        }

        @unlink($file);
    }

    public function test_persian_variants_are_not_a_change(): void
    {
        $this->createExistingPerson('1234567890', 'احمد', 'محمدی');

        $file = $this->csv([
            $this->personRow('1234567890', 'أحمد', 'محمدي'),
        ]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('unchanged', $results['preview'][0]['status']);
        $this->assertArrayNotHasKey('changes', $results['preview'][0]);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertSame(0, $results['created']);

        $confirm = $this->scopedImport();
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $this->assertSame(0, $confirm->getImportResults()['updated']);
        $this->assertSame(1, $confirm->getImportResults()['skipped']);
        $this->assertSame([], $confirm->getImportResults()['changes']);

        @unlink($file);
    }

    public function test_genuinely_different_names_are_folded_on_write_and_stable_on_reimport(): void
    {
        $this->createExistingPerson('1234567890', 'احمد', 'محمدی');

        $file = $this->csv([
            $this->personRow('1234567890', 'أکبر', 'رضايي'),
        ]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $preview = $import->getImportResults()['preview'][0];

        $this->assertSame('update', $preview['status']);
        $this->assertSame([
            'f_name' => ['old' => 'احمد', 'new' => 'اکبر'],
            'l_name' => ['old' => 'محمدی', 'new' => 'رضایی'],
        ], $preview['changes']);

        $confirm = $this->scopedImport();
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $this->assertSame(1, $confirm->getImportResults()['updated']);
        $this->assertDatabaseHas('persons', [
            'n_code' => '1234567890',
            'f_name' => 'اکبر',
            'l_name' => 'رضایی',
        ]);

        $reimport = $this->scopedImport();
        Excel::import($reimport, $file);

        $this->assertSame('unchanged', $reimport->getImportResults()['preview'][0]['status']);
        $this->assertSame(0, $reimport->getImportResults()['updated']);
        $this->assertSame(1, $reimport->getImportResults()['skipped']);

        @unlink($file);
    }
}
