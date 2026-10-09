<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

#[CoversNothing]

class ReportsAdvancedLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_advanced_report_renders_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertStatus(200);
    }

    public function test_advanced_report_mounts_with_default_dates(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertSet('reportType', 'tickets')
            ->assertSet('statusFilter', 'all')
            ->assertSet('dateFrom', fn ($val) => ! empty($val) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $val))
            ->assertSet('dateTo', fn ($val) => ! empty($val) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $val));
    }

    public function test_advanced_report_has_units_loaded(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertSet('units', fn ($units) => count($units) >= 1);
    }

    /**
     * Issue #915 — `reportType = todos` filtered the `todos` table on a
     * `status` column that does not exist (SQLSTATE 42703). The filter is
     * type-aware now: `completed` maps to `is_completed = true` and must
     * exclude an incomplete todo.
     */
    public function test_advanced_report_todos_completed_filter_excludes_incomplete_todos(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        Todo::factory()->completed()->create([
            'title' => 'کار تکمیل‌شده گزارش پیشرفته',
            'unit_id' => $unit->id,
        ]);
        Todo::factory()->create([
            'title' => 'کار ناتمام گزارش پیشرفته',
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);

        $component = Livewire::test('reports.advanced')
            ->set('reportType', 'todos')
            ->set('statusFilter', 'completed')
            ->assertStatus(200);

        $data = $component->instance()->reportData();

        $this->assertSame(1, $data['total']);
    }

    /**
     * Issue #915 — `pending` means `is_completed = false` with no deadline
     * or a future deadline; `overdue` means `is_completed = false` with
     * `end_at < now()`. Fixtures sit on both sides of that boundary, and a
     * null-`end_at` todo belongs to `pending`.
     */
    public function test_advanced_report_todos_pending_filter_includes_null_deadline_todos(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        Todo::factory()->create([
            'title' => 'کار بدون سررسید گزارش پیشرفته',
            'unit_id' => $unit->id,
            'is_completed' => false,
            'end_at' => null,
        ]);
        Todo::factory()->completed()->create([
            'title' => 'کار تکمیل‌شده گزارش پیشرفته',
            'unit_id' => $unit->id,
        ]);

        $component = Livewire::test('reports.advanced')
            ->set('reportType', 'todos')
            ->set('statusFilter', 'pending')
            ->assertStatus(200);

        $this->assertSame(1, $component->instance()->reportData()['total']);
    }

    public function test_advanced_report_todos_overdue_filter_matches_only_past_deadline_todos(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        Todo::factory()->overdue()->create([
            'title' => 'کار سررسیدگذشته گزارش پیشرفته',
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);
        Todo::factory()->create([
            'title' => 'کار آینده‌دار گزارش پیشرفته',
            'unit_id' => $unit->id,
            'is_completed' => false,
            'end_at' => now()->addDays(5),
        ]);

        $component = Livewire::test('reports.advanced')
            ->set('reportType', 'todos')
            ->set('statusFilter', 'overdue')
            ->assertStatus(200);

        $this->assertSame(1, $component->instance()->reportData()['total']);
    }

    /**
     * Issue #915 — `statusFilter` is public Livewire state with no
     * validation. An unknown value falls back to `'all'` instead of reaching
     * a column reference.
     */
    public function test_advanced_report_unknown_status_filter_falls_back_to_all(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->set('reportType', 'todos')
            ->set('statusFilter', 'nonsense')
            ->assertStatus(200)
            ->assertSet('statusFilter', 'all');
    }

    /**
     * Issue #915 — switching `reportType` resets `statusFilter` to `'all'`,
     * so a value picked under `tickets` never arrives at the `todos`
     * predicates and 500s on arrival.
     */
    public function test_advanced_report_switching_report_type_resets_status_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->set('statusFilter', 'created')
            ->set('reportType', 'todos')
            ->assertStatus(200)
            ->assertSet('statusFilter', 'all');
    }

    /**
     * Issue #915 — 18 of 50 seeded tickets (`accepted` + `rejected`) were
     * unfilterable while the unfiltered report still counted them. Every
     * ticket status must be selectable and must filter to exactly its rows.
     */
    public function test_advanced_report_tickets_accepted_and_rejected_are_filterable(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        foreach (['created', 'forwarded', 'accepted', 'completed', 'rejected'] as $status) {
            Ticket::create([
                'ticket_code' => 'T915-'.strtoupper($status),
                'user_id' => $user->id,
                'unit_id' => $unit->id,
                'subject' => 'تیکت '.$status,
                'content' => 'محتوای تست',
                'priority' => 'normal',
                'status' => $status,
            ]);
        }

        foreach (['accepted', 'rejected'] as $status) {
            $component = Livewire::test('reports.advanced')
                ->set('reportType', 'tickets')
                ->set('statusFilter', $status)
                ->assertStatus(200);

            $this->assertSame(1, $component->instance()->reportData()['total'], "status {$status} must filter to its own row");
        }
    }

    /**
     * Issue #915 — `persons` has a real `status` column
     * (`active|inactive|retired`) but the old guard disabled the filter while
     * the UI kept offering it. The filter applies to `persons.status` now.
     */
    public function test_advanced_report_persons_status_filter_applies_to_persons_status(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        Person::factory()->create(['u_id' => $unit->id, 'status' => 'inactive']);
        Person::factory()->create(['u_id' => $unit->id, 'status' => 'retired']);

        $component = Livewire::test('reports.advanced')
            ->set('reportType', 'persons')
            ->set('statusFilter', 'inactive')
            ->assertStatus(200);

        $this->assertSame(1, $component->instance()->reportData()['total']);
    }

    /**
     * Issue #915 — the rendered select options and the whitelist must be the
     * same data. Every value the select renders is a known option for its
     * report type, and every status present in `tickets` and `persons` has
     * an option, so a new status cannot silently become unfilterable again.
     */
    public function test_advanced_report_status_options_round_trip_with_predicates(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_personnel');
        $this->actingAs($user);

        $reflection = new \ReflectionClass(Livewire::test('reports.advanced')->instance());
        /** @var array<string, array<string, string>> $options */
        $options = $reflection->getConstant('STATUS_OPTIONS');
        $this->assertArrayHasKey('tickets', $options);
        $this->assertArrayHasKey('todos', $options);
        $this->assertArrayHasKey('persons', $options);

        foreach (Ticket::distinct()->pluck('status') as $status) {
            $this->assertArrayHasKey($status, $options['tickets'], "ticket status {$status} must have an option");
        }

        foreach (Person::distinct()->pluck('status') as $status) {
            $this->assertArrayHasKey($status, $options['persons'], "person status {$status} must have an option");
        }

        $component = Livewire::test('reports.advanced');

        foreach ($options as $reportType => $values) {
            $html = $component->set('reportType', $reportType)->html();

            foreach (array_keys($values) as $value) {
                $this->assertStringContainsString('value="'.$value.'"', $html, "{$value} must be rendered for {$reportType}");
            }
        }
    }
}
