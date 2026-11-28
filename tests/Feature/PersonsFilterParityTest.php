<?php

namespace Tests\Feature;

use App\Exports\PersonsExport;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1963 — the persons API list searched the raw `f_name`/`l_name`
 * columns while the xlsx export (and the Livewire list) already searched the
 * stored folded columns. The same query therefore returned different rows on
 * the two surfaces, and a row whose name reached the table without the
 * model's `saving` hook (#815) was invisible in the list but found by the
 * export.
 *
 * This test pins list/export parity for the single-term, person-name searches
 * the two surfaces share. It deliberately does NOT claim general parity:
 *
 *   - the list keeps its single-term shape, the export ANDs its terms;
 *   - the list has no unit-name arm, the export does.
 *
 * Every fixture below is chosen so both limitations are irrelevant: one term,
 * no term matching the unit name, and the target row contains the term as a
 * contiguous substring of `full_name_folded` or `full_name_rev_folded`.
 */
class PersonsFilterParityTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('folded columns are PostgreSQL-only');
        }

        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        ['user' => $this->user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel', 'view_hr_dashboard']);

        // No search term below matches this unit name, and the helper's own
        // person is renamed so it cannot pollute the exact row sets.
        $unit->update(['name' => 'واحد سنجش']);
        $this->user->person->update(['f_name' => 'پرونده', 'l_name' => 'آزمایشی']);

        // A) A name written without the model's saving hook, so the stored
        // text keeps its Arabic yeh/kaf and only the folded column bridges
        // the two spellings (same fixture as PersonsExportTest:353).
        DB::table('persons')->insert([
            'n_code' => '0012345678',
            'f_name' => 'عسگري',   // Arabic yeh, never normalized
            'l_name' => 'كريبى',   // Arabic kaf, never normalized
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $unit->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // B) A ZWNJ surname written raw; the folded column turns the ZWNJ
        // into a space, exactly like the search term folding does (#940).
        DB::table('persons')->insert([
            'n_code' => '0023456789',
            'f_name' => 'مهدی',
            'l_name' => "حسین\u{200C}زاده",
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $unit->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // C) A normal row for the reversed-name scenario (#494).
        Person::factory()->create([
            'n_code' => '0034567890',
            'f_name' => 'علی',
            'l_name' => 'رضایی',
            'u_id' => $unit->id,
        ]);

        // D) A decoy that no term below matches.
        Person::factory()->create([
            'n_code' => '0045678901',
            'f_name' => 'زهرا',
            'l_name' => 'کریمی',
            'u_id' => $unit->id,
        ]);

        $this->token = $this->createApiToken($this->user, ['persons:read', 'hr:read']);
    }

    public function test_unfolded_row_matches_in_list_and_export(): void
    {
        $this->assertSame(
            ['0012345678'],
            $this->exportCodes($this->user, 'عسگری')
        );
        $this->assertSame(
            ['0012345678'],
            $this->listCodes($this->token, 'عسگری')
        );
    }

    public function test_zwnj_surname_matches_in_list_and_export(): void
    {
        $this->assertSame(
            ['0023456789'],
            $this->exportCodes($this->user, "حسین\u{200C}زاده")
        );
        $this->assertSame(
            ['0023456789'],
            $this->listCodes($this->token, "حسین\u{200C}زاده")
        );
    }

    public function test_reversed_name_matches_in_list_and_export(): void
    {
        $this->assertSame(
            ['0034567890'],
            $this->exportCodes($this->user, 'رضایی علی')
        );
        $this->assertSame(
            ['0034567890'],
            $this->listCodes($this->token, 'رضایی علی')
        );
    }

    public function test_n_code_still_matches_in_list_and_export(): void
    {
        $this->assertSame(
            ['0012345678'],
            $this->exportCodes($this->user, '0012345678')
        );
        $this->assertSame(
            ['0012345678'],
            $this->listCodes($this->token, '0012345678')
        );
    }

    public function test_hr_personnel_agrees_with_the_export_for_a_folded_only_row(): void
    {
        $export = $this->exportCodes($this->user, 'عسگری');

        $response = $this->apiGet('/api/hr/personnel?search='.urlencode('عسگری'), $this->token);
        $response->assertOk();

        $hr = array_column($response->json('data'), 'n_code');
        sort($hr);

        $this->assertSame($export, $hr);
        $this->assertSame(['0012345678'], $hr);
    }

    /**
     * The national codes the API list returns for a search, sorted.
     *
     * @return list<string>
     */
    private function listCodes(string $token, string $search): array
    {
        $response = $this->apiGet('/api/persons?search='.urlencode($search), $token);
        $response->assertOk();

        $codes = array_column($response->json('data'), 'n_code');
        sort($codes);

        return $codes;
    }

    /**
     * The national codes the xlsx export returns for a search, sorted.
     *
     * @return list<string>
     */
    private function exportCodes(User $user, string $search): array
    {
        // apiGet() calls forgetGuards(), so the session guard must be set
        // after any API request, not before it.
        $this->actingAs($user);

        $response = $this->get(route('kargozini.persons.export', ['search' => $search]));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'parity-').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $path);

        $sheet = IOFactory::load($path);
        $rows = $sheet->getActiveSheet()->toArray(null, true, true, true);
        array_shift($rows);

        $index = array_search('کد ملی', (new PersonsExport(Person::query()))->headings(), true);
        $this->assertNotFalse($index, 'کد ملی column must exist');

        $letter = Coordinate::stringFromColumnIndex($index + 1);
        $codes = array_values(array_filter(array_map(fn ($row) => $row[$letter] ?? null, $rows)));

        $sheet->disconnectWorksheets();
        sort($codes);

        return $codes;
    }
}
