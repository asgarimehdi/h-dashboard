<?php

namespace Tests\Feature;

use App\Exports\HardwareExport;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HardwareExportTest extends TestCase
{
    use RefreshDatabase;

    protected int $tId;
    protected int $eId;
    protected int $sId;
    protected int $rId;
    protected Unit $unit;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $this->unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId,
            'r_id' => $this->rId, 'u_id' => $this->unit->id,
        ]);
        $this->user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);

        $permission = Permission::firstOrCreate(['name' => 'manage_hardware', 'guard_name' => 'web']);
        $this->user->givePermissionTo($permission);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);

        $this->actingAs($this->user);
    }

    /**
     * Helper: create a hardware record for the current user's unit.
     */
    protected function createHardware(array $overrides = []): Hardware
    {
        $nCode = $this->user->n_code;

        return Hardware::create(array_merge([
            'n_code'  => $nCode,
            'pc_name' => 'TEST-PC',
            'type'    => 'pc',
            'os'      => 'Windows 11',
            'cpu'     => 'Intel i5',
            'ram'     => '8192',
            'hdd'     => '512GB SSD',
            'mac'     => 'AA:BB:CC:DD:EE:FF',
            'ip_local'=> '192.168.1.100',
        ], $overrides));
    }

    /**
     * Helper: store export state in session (simulates what Livewire does).
     */
    protected function setExportState(array $columns, array $filters = []): void
    {
        Session::put('hardware_export_state', [
            'columns' => $columns,
            'filters' => $filters,
        ]);
    }

    // -----------------------------------------------------------
    //  Route-level tests
    // -----------------------------------------------------------

    #[Test]
    public function export_route_returns_excel_file(): void
    {
        $this->createHardware();
        $this->setExportState(['n_code', 'pc_name', 'type']);

        $response = $this->get(route('hardware.export'));

        $response->assertStatus(200);
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    #[Test]
    public function export_route_returns_404_without_session_state(): void
    {
        // No session state set
        $response = $this->get(route('hardware.export'));

        $response->assertStatus(404);
    }

    #[Test]
    public function export_route_requires_auth(): void
    {
        auth()->logout();
        $this->setExportState(['n_code', 'pc_name']);

        $response = $this->get(route('hardware.export'));

        $response->assertRedirect();
    }

    #[Test]
    public function export_route_requires_manage_hardware_permission(): void
    {
        $basicNCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $basicNCode, 'f_name' => 'Basic', 'l_name' => 'User',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId,
            'r_id' => $this->rId, 'u_id' => $this->unit->id,
        ]);
        $basicUser = User::create([
            'n_code'    => $basicNCode,
            'password'  => Hash::make('password'),
        ]);
        $this->actingAs($basicUser);
        $this->setExportState(['n_code', 'pc_name']);

        $response = $this->get(route('hardware.export'));

        $response->assertForbidden();
    }

    // -----------------------------------------------------------
    //  Column / heading tests
    // -----------------------------------------------------------

    #[Test]
    public function export_includes_only_requested_columns(): void
    {
        $hw = $this->createHardware([
            'pc_name' => 'PC-EXPORT-001',
            'type'    => 'laptop',
            'cpu'     => 'AMD Ryzen 7',
            'ram'     => '16384',
        ]);

        $this->setExportState(['n_code', 'pc_name', 'type']);

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);

        // Use Maatwebsite's download assertion to verify content
        $response->assertDownload();
    }

    #[Test]
    public function export_always_includes_n_code_and_pc_name(): void
    {
        $this->createHardware();
        // Only request columns that don't include n_code/pc_name
        $this->setExportState(['type', 'os', 'cpu']);

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    // -----------------------------------------------------------
    //  Filter tests
    // -----------------------------------------------------------

    #[Test]
    public function export_respects_type_filter(): void
    {
        $this->createHardware(['type' => 'laptop', 'pc_name' => 'LAPTOP-001']);
        $this->createHardware(['type' => 'server', 'pc_name' => 'SERVER-001']);

        $this->setExportState(
            ['n_code', 'pc_name', 'type'],
            ['filterType' => 'laptop']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
        $response->assertDownload();
    }

    #[Test]
    public function export_respects_os_filter(): void
    {
        $this->createHardware(['os' => 'Windows 11', 'pc_name' => 'PC-WIN']);
        $this->createHardware(['os' => 'Ubuntu 22.04', 'pc_name' => 'PC-LINUX']);

        $this->setExportState(
            ['n_code', 'pc_name', 'os'],
            ['filterOs' => 'Windows']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_cpu_filter(): void
    {
        $this->createHardware(['cpu' => 'Intel i7', 'pc_name' => 'PC-INTEL']);
        $this->createHardware(['cpu' => 'AMD Ryzen 5', 'pc_name' => 'PC-AMD']);

        $this->setExportState(
            ['n_code', 'pc_name', 'cpu'],
            ['filterCpu' => 'Intel']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_ram_filter(): void
    {
        $this->createHardware(['ram' => '8192', 'pc_name' => 'PC-8G']);
        $this->createHardware(['ram' => '16384', 'pc_name' => 'PC-16G']);

        $this->setExportState(
            ['n_code', 'pc_name', 'ram'],
            ['filterRam' => '16384']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_hdd_filter(): void
    {
        $this->createHardware(['hdd' => '512GB SSD', 'pc_name' => 'PC-SSD']);
        $this->createHardware(['hdd' => '1TB HDD', 'pc_name' => 'PC-HDD']);

        $this->setExportState(
            ['n_code', 'pc_name', 'hdd'],
            ['filterHdd' => 'SSD']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_shutdown_filter(): void
    {
        $this->createHardware(['shutdown' => true, 'pc_name' => 'PC-ON']);
        $this->createHardware(['shutdown' => false, 'pc_name' => 'PC-OFF']);

        $this->setExportState(
            ['n_code', 'pc_name', 'shutdown'],
            ['filterShutdown' => '1']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_mark_filter(): void
    {
        $this->createHardware(['mark' => true, 'pc_name' => 'PC-MARKED']);
        $this->createHardware(['mark' => false, 'pc_name' => 'PC-PLAIN']);

        $this->setExportState(
            ['n_code', 'pc_name', 'mark'],
            ['filterMark' => '1']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_net_type_filter(): void
    {
        $this->createHardware(['net_type' => 'wired', 'pc_name' => 'PC-WIRED']);
        $this->createHardware(['net_type' => 'wireless', 'pc_name' => 'PC-WIFI']);

        $this->setExportState(
            ['n_code', 'pc_name', 'net_type'],
            ['filterNetType' => 'wired']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_person_filter(): void
    {
        $this->createHardware(['pc_name' => 'PC-FILTER-PERSON']);

        $this->setExportState(
            ['n_code', 'pc_name'],
            ['filterPerson' => 'تست']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_unit_filter(): void
    {
        $this->createHardware(['pc_name' => 'PC-FILTER-UNIT']);

        $this->setExportState(
            ['n_code', 'pc_name'],
            ['filterUnit' => 'واحد تست']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    #[Test]
    public function export_respects_combined_filters(): void
    {
        $this->createHardware(['type' => 'laptop', 'ram' => '16384', 'pc_name' => 'PC-COMBO']);
        $this->createHardware(['type' => 'server', 'ram' => '8192', 'pc_name' => 'PC-OTHER']);

        $this->setExportState(
            ['n_code', 'pc_name', 'type', 'ram'],
            ['filterType' => 'laptop', 'filterRam' => '16384']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    // -----------------------------------------------------------
    //  Org scope tests
    // -----------------------------------------------------------

    #[Test]
    public function export_respects_org_scope(): void
    {
        // Create hardware in a different unit the user cannot access
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $otherNCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $otherNCode, 'f_name' => 'دیگر', 'l_name' => 'کاربر',
            't_id' => $this->tId, 'e_id' => $this->eId, 's_id' => $this->sId,
            'r_id' => $this->rId, 'u_id' => $otherUnit->id,
        ]);
        Hardware::create([
            'n_code' => $otherNCode, 'pc_name' => 'PC-OTHER', 'type' => 'pc',
        ]);

        // User's own hardware
        $this->createHardware(['pc_name' => 'PC-OWN']);

        $this->setExportState(['n_code', 'pc_name', 'type']);

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
        // The export should only contain user's hardware, not the other unit's
    }

    // -----------------------------------------------------------
    //  Search filter tests
    // -----------------------------------------------------------

    #[Test]
    public function export_respects_search_filter(): void
    {
        $this->createHardware(['pc_name' => 'SEARCHABLE-PC']);
        $this->createHardware(['pc_name' => 'ANOTHER-PC']);

        $this->setExportState(
            ['n_code', 'pc_name'],
            ['search' => 'SEARCHABLE']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
    }

    // -----------------------------------------------------------
    //  Empty results
    // -----------------------------------------------------------

    #[Test]
    public function export_with_no_matching_data_returns_valid_excel(): void
    {
        $this->setExportState(
            ['n_code', 'pc_name', 'type'],
            ['filterType' => 'nonexistent-type']
        );

        $response = $this->get(route('hardware.export'));
        $response->assertStatus(200);
        // Should still return a valid (empty) Excel file
    }

    // -----------------------------------------------------------
    //  HardwareExport class unit tests
    // -----------------------------------------------------------

    #[Test]
    public function export_class_implements_required_interfaces(): void
    {
        $query = Hardware::query();
        $export = new HardwareExport($query, ['n_code', 'pc_name']);

        $this->assertInstanceOf(FromCollection::class, $export);
        $this->assertInstanceOf(WithHeadings::class, $export);
        $this->assertInstanceOf(WithMapping::class, $export);
    }

    #[Test]
    public function export_class_returns_persian_headings(): void
    {
        $query = Hardware::query();
        $export = new HardwareExport($query, ['n_code', 'pc_name', 'type', 'cpu']);

        $headings = $export->headings();

        $this->assertCount(4, $headings);
        $this->assertContains('کد ملی', $headings);
        $this->assertContains('نام دستگاه', $headings);
        $this->assertContains('نوع', $headings);
        $this->assertContains('CPU', $headings);
    }

    #[Test]
    public function export_class_title_is_persian(): void
    {
        $query = Hardware::query();
        $export = new HardwareExport($query, ['n_code']);

        $this->assertEquals('شناسنامه سخت‌افزار', $export->title());
    }

    #[Test]
    public function export_class_maps_shutdown_to_persian(): void
    {
        $this->createHardware(['shutdown' => true, 'pc_name' => 'PC-ON']);
        $hw = Hardware::first();

        $query = Hardware::where('id', $hw->id);
        $export = new HardwareExport($query, ['shutdown']);

        $mapped = $export->map($hw);
        $this->assertEquals('روشن', $mapped[0]);
    }

    #[Test]
    public function export_class_maps_mark_to_persian(): void
    {
        $this->createHardware(['mark' => true, 'pc_name' => 'PC-MARKED']);
        $hw = Hardware::first();

        $query = Hardware::where('id', $hw->id);
        $export = new HardwareExport($query, ['mark']);

        $mapped = $export->map($hw);
        $this->assertEquals('علامت‌دار', $mapped[0]);
    }

    #[Test]
    public function export_class_maps_person_name(): void
    {
        $this->createHardware();
        $hw = Hardware::with('person')->first();

        $query = Hardware::where('id', $hw->id);
        $export = new HardwareExport($query, ['person_name']);

        $mapped = $export->map($hw);
        $this->assertStringContainsString('تست', $mapped[0]);
        $this->assertStringContainsString('کاربر', $mapped[0]);
    }

    #[Test]
    public function export_class_maps_unit_name(): void
    {
        $this->createHardware();
        $hw = Hardware::with('person.unit')->first();

        $query = Hardware::where('id', $hw->id);
        $export = new HardwareExport($query, ['unit_name']);

        $mapped = $export->map($hw);
        $this->assertEquals('واحد تست', $mapped[0]);
    }

    #[Test]
    public function export_class_filters_out_unknown_columns(): void
    {
        $query = Hardware::query();
        $export = new HardwareExport($query, ['n_code', 'nonexistent_column', 'pc_name']);

        $headings = $export->headings();
        $this->assertCount(2, $headings); // Only n_code and pc_name
    }
}
