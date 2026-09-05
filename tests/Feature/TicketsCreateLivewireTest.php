<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Notification;
use App\Models\Person;
use App\Models\TaskActivity;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(Ticket::class);

class TicketsCreateLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        // Resync Postgres sequences for tables seeded with explicit id=1
        // so later inserts do not collide with the explicit primary key.
        foreach (['tahsils', 'estekhdams', 'semats', 'radifs'] as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT MAX(id) FROM {$table}), 1))");
        }
    }

    /**
     * Create a user that belongs to a unit, optionally with a permission.
     */
    protected function createUserWithUnit(string $permission = 'create_ticket'): array
    {
        $unit = Unit::create(['name' => 'واحد مبدا', 'is_active' => true]);
        $nCode = (string) fake()->unique()->numerify('##########');

        Person::create([
            'n_code' => $nCode,
            'f_name' => 'تست',
            'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1,
            'u_id' => $unit->id,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->givePermissionTo($permission);

        return ['user' => $user, 'unit' => $unit];
    }

    /**
     * Create a destination unit capable of receiving tickets.
     */
    protected function createTargetUnit(string $name = 'واحد مقصد'): Unit
    {
        return Unit::create([
            'name' => $name,
            'is_active' => true,
            'can_receive_tickets' => true,
        ]);
    }

    // ==================== Auth / RBAC ====================

    public function test_guest_302(): void
    {
        $this->get('/tickets/new')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        // Strip the permission back off so the route is denied.
        $data['user']->revokePermissionTo('create_ticket');

        $this->actingAs($data['user'])
            ->get('/tickets/new')
            ->assertStatus(403);
    }

    public function test_renders(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        Session::put('current_unit_id', $data['unit']->id);

        Livewire::actingAs($data['user'])
            ->test('tickets.create')
            ->assertOk()
            ->assertSee('ایجاد تیکت جدید')
            ->assertSee('موضوع تیکت')
            ->assertSee('شرح درخواست')
            ->assertSee('پیوست مستندات');
    }

    // ==================== mount / loadData ====================

    public function test_loads_todos(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $unit = $data['unit'];
        $user = $data['user'];
        Session::put('current_unit_id', $unit->id);

        // Open todo for current unit — should be loaded.
        $openTodo = Todo::create([
            'title' => 'وظیفه باز',
            'start_at' => now(),
            'end_at' => now()->addWeek(),
            'is_completed' => false,
            'unit_id' => $unit->id,
        ]);

        // Completed todo for current unit — should NOT be loaded.
        Todo::create([
            'title' => 'وظیفه بسته',
            'start_at' => now(),
            'end_at' => now()->addWeek(),
            'is_completed' => true,
            'unit_id' => $unit->id,
        ]);

        // Open todo for another unit — should NOT be loaded.
        $otherUnit = Unit::create(['name' => 'سایر', 'is_active' => true]);
        Todo::create([
            'title' => 'وظیفه واحد دیگر',
            'start_at' => now(),
            'end_at' => now()->addWeek(),
            'is_completed' => false,
            'unit_id' => $otherUnit->id,
        ]);

        $component = Livewire::actingAs($user)->test('tickets.create');

        $todos = $component->get('todos');
        $this->assertCount(1, $todos);
        $this->assertEquals($openTodo->id, $todos[0]['id']);
    }

    // ==================== Search / dropdown ====================

    public function test_search_units(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        $unit = $data['unit'];
        Session::put('current_unit_id', $unit->id);

        $targetA = $this->createTargetUnit('بیمارستان هدف الف');
        $targetB = $this->createTargetUnit('بیمارستان هدف ب');
        $inactive = Unit::create([
            'name' => 'بیمارستان غیرفعال',
            'is_active' => false,
            'can_receive_tickets' => true,
        ]);
        $noTickets = Unit::create([
            'name' => 'بیمارستان فاقد دریافت',
            'is_active' => true,
            'can_receive_tickets' => false,
        ]);

        // 1 char search: should clear and not show dropdown.
        $component = Livewire::actingAs($user)->test('tickets.create')
            ->set('search', 'ب')
            ->assertSet('units', [])
            ->assertSet('unit_id', null);

        // 2+ chars: only active + can_receive_tickets, excluding own unit, max 5.
        $component->set('search', 'بیمارستان')
            ->assertSet('showDropdown', true);

        $units = $component->get('units');
        $this->assertCount(2, $units, 'Expected 2 units, got '.count($units).': '.json_encode(array_column($units, 'id')));
        $ids = array_column($units, 'id');
        $this->assertContains($targetA->id, $ids);
        $this->assertContains($targetB->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($noTickets->id, $ids);
        $this->assertNotContains($unit->id, $ids);
    }

    public function test_select_unit(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        Session::put('current_unit_id', $data['unit']->id);

        $target = $this->createTargetUnit('واحد انتخابی');

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->call('selectUnit', $target->id, $target->name)
            ->assertSet('unit_id', $target->id)
            ->assertSet('search', $target->name)
            ->assertSet('showDropdown', false);
    }

    // ==================== Reset / file ====================

    public function test_reset_form_clears_fields(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        Session::put('current_unit_id', $data['unit']->id);

        $target = $this->createTargetUnit();

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('search', 'X')
            ->set('subject', 'موضوع تست')
            ->set('content', 'محتوای تست تست')
            ->set('priority', 'urgent')
            ->call('resetForm')
            ->assertSet('subject', '')
            ->assertSet('content', '')
            ->assertSet('priority', 'normal')
            ->assertSet('unit_id', null)
            ->assertSet('search', '')
            ->assertSet('showDropdown', false);
    }

    public function test_remove_file_clears_index(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        Session::put('current_unit_id', $data['unit']->id);

        $file1 = UploadedFile::fake()->create('doc1.pdf', 10);
        $file2 = UploadedFile::fake()->create('doc2.pdf', 10);

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('files', [$file1, $file2])
            ->call('removeFile', 0)
            ->assertCount('files', 1);
    }

    // ==================== saveTicket / persistence ====================

    public function test_save_creates_ticket(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        $unit = $data['unit'];
        Session::put('current_unit_id', $unit->id);

        $target = $this->createTargetUnit('واحد مقصد اصلی');

        // Notification recipient on target unit.
        $recipientN = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $recipientN, 'f_name' => 'گیرنده', 'l_name' => 'تست',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1,
            'u_id' => $target->id,
        ]);
        $recipient = User::create(['n_code' => $recipientN, 'password' => Hash::make('password')]);
        $recipient->units()->attach($target->id, ['role' => 'staff', 'is_primary' => true]);

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', 'تیکت تستی ایجاد')
            ->set('content', 'این محتوای تستی برای تیکت است و بیش از ده کاراکتر دارد')
            ->set('priority', 'urgent')
            ->call('saveTicket')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tickets', [
            'unit_id' => $target->id,
            'user_id' => $user->id,
            'subject' => 'تیکت تستی ایجاد',
            'priority' => 'urgent',
            'status' => 'created',
        ]);

        // The ticket_code must match TK- prefix.
        $ticket = Ticket::where('subject', 'تیکت تستی ایجاد')->firstOrFail();
        $this->assertStringStartsWith('TK-', $ticket->ticket_code);

        // ActivityLog + TaskActivity.
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Ticket::class,
            'subject_id' => $ticket->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('task_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => 'created',
            'to_unit_id' => $target->id,
        ]);

        // Notification for the recipient on the target unit.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipient->id,
            'type' => 'ticket_created',
            'title' => 'تیکت جدید دریافت شد',
        ]);

        // Form must reset on the original component after a successful save.
        $component = Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', 'موضوع اول تست ریست')
            ->set('content', 'محتوای تستی برای ریست فرم پس از ثبت')
            ->call('saveTicket');
        $component->assertSet('subject', '')
            ->assertSet('content', '')
            ->assertSet('unit_id', null)
            ->assertSet('search', '');
    }

    public function test_auto_creates_todo(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        $unit = $data['unit'];
        Session::put('current_unit_id', $unit->id);

        $target = $this->createTargetUnit('واحد تودو');

        $initialTodos = Todo::count();

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', 'تیکت با تولید خودکار وظیفه')
            ->set('content', 'محتوای تستی برای تولید خودکار وظیفه جدید')
            ->call('saveTicket')
            ->assertHasNoErrors();

        $this->assertEquals($initialTodos + 1, Todo::count());

        $todo = Todo::where('title', 'تیکت با تولید خودکار وظیفه')->firstOrFail();
        $this->assertFalse((bool) $todo->is_completed);
        $this->assertEquals($target->id, $todo->unit_id);

        // Ticket must reference the new todo via task_id.
        $this->assertDatabaseHas('tickets', [
            'subject' => 'تیکت با تولید خودکار وظیفه',
            'task_id' => $todo->id,
        ]);
    }

    public function test_links_existing_todo_when_task_id_provided(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        $unit = $data['unit'];
        Session::put('current_unit_id', $unit->id);

        $target = $this->createTargetUnit('واحد با تسک موجود');
        $existingTodo = Todo::create([
            'title' => 'وظیفه موجود',
            'start_at' => now(),
            'end_at' => now()->addWeek(),
            'is_completed' => false,
            'unit_id' => $unit->id,
        ]);

        $initialTodos = Todo::count();

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('task_id', $existingTodo->id)
            ->set('subject', 'تیکت مرتبط با وظیفه موجود')
            ->set('content', 'محتوای تستی برای پیوند به وظیفه موجود')
            ->call('saveTicket')
            ->assertHasNoErrors();

        // No new todo must be created.
        $this->assertEquals($initialTodos, Todo::count());

        $this->assertDatabaseHas('tickets', [
            'subject' => 'تیکت مرتبط با وظیفه موجود',
            'task_id' => $existingTodo->id,
        ]);
    }

    public function test_attachments_are_persisted(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        $unit = $data['unit'];
        Session::put('current_unit_id', $unit->id);

        $target = $this->createTargetUnit('واحد پیوست');

        StorageFake();

        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', 'تیکت با پیوست')
            ->set('content', 'محتوای تستی برای بررسی پیوست فایل')
            ->set('files', [$file])
            ->call('saveTicket')
            ->assertHasNoErrors();

        $ticket = Ticket::where('subject', 'تیکت با پیوست')->firstOrFail();
        $this->assertEquals(1, Attachment::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('attachments', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_name' => 'report.pdf',
        ]);
    }

    // ==================== Validation ====================

    public function test_validation_errors(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        Session::put('current_unit_id', $data['unit']->id);

        // Empty payload → 3 required errors.
        Livewire::actingAs($user)
            ->test('tickets.create')
            ->call('saveTicket')
            ->assertHasErrors(['unit_id', 'subject', 'content']);
    }

    public function test_validation_min_max_lengths(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        Session::put('current_unit_id', $data['unit']->id);

        $target = $this->createTargetUnit();

        // subject too short, content too short
        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', 'abc')
            ->set('content', 'short')
            ->call('saveTicket')
            ->assertHasErrors(['subject', 'content']);
    }

    public function test_own_unit_rejected(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        $unit = $data['unit'];
        Session::put('current_unit_id', $unit->id);

        // The user's own unit — must be rejected by the custom rule.
        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $unit->id)
            ->set('subject', 'ارسال به واحد خود')
            ->set('content', 'محتوای تستی رد شدن ارسال به واحد خود')
            ->call('saveTicket')
            ->assertHasErrors(['unit_id']);

        // No ticket must have been created.
        $this->assertDatabaseMissing('tickets', ['subject' => 'ارسال به واحد خود']);
    }

    public function test_unit_id_required_and_exists(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        Session::put('current_unit_id', $data['unit']->id);

        // Pick a non-existent unit id.
        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', 99999)
            ->set('subject', 'تیکت تستی واحد نامعتبر')
            ->set('content', 'محتوای تستی برای واحد نامعتبر در ولیدیشن')
            ->call('saveTicket')
            ->assertHasErrors(['unit_id']);
    }

    public function test_file_validation_rejects_too_many_files(): void
    {
        $data = $this->createUserWithUnit('create_ticket');
        $user = $data['user'];
        Session::put('current_unit_id', $data['unit']->id);

        $files = [
            UploadedFile::fake()->create('a.pdf', 10),
            UploadedFile::fake()->create('b.pdf', 10),
            UploadedFile::fake()->create('c.pdf', 10),
            UploadedFile::fake()->create('d.pdf', 10),
            UploadedFile::fake()->create('e.pdf', 10),
            UploadedFile::fake()->create('f.pdf', 10),
        ];

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('files', $files)
            ->assertHasErrors(['files'])
            ->assertSet('files', []);
    }
}

if (! function_exists('Tests\Feature\StorageFake')) {
    function StorageFake(): void
    {
        // Helper no-op — Livewire's WithFileUploads uses Storage::fake('public')
        // in setUp of feature tests via automatic framework boot; this stub keeps
        // the test fluent without dragging the facade into every test method.
    }
}
