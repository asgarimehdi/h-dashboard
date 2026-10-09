<?php

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

covers(User::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    $this->user = User::factory()->create([
        'n_code' => '1234567890',
        'password' => Hash::make('password'),
    ]);
});

test('guest is redirected from profile page', function () {
    $this->get('/profile')->assertRedirect('/login');
});

test('authenticated user can load profile page', function () {
    $this->actingAs($this->user);
    $this->get('/profile')->assertStatus(200);
});

test('profile page shows user name and stats placeholders', function () {
    $this->actingAs($this->user);

    Livewire::test('profile.index')
        ->assertSee('پروفایل من')
        ->assertSee('تست کاربر')
        ->assertSee('تیکت‌ها')
        ->assertSee('وظایف')
        ->assertSee('فعالیت‌ها');
});

test('profile page shows no tickets empty state initially', function () {
    $this->actingAs($this->user);

    Livewire::test('profile.index')
        ->assertSee('هنوز تیکتی ثبت نشده');
});

// Issue #893: the "My todos" tab listed every todo in the unit, not the viewer's
// own. The tickets tab two methods above already filters by `user_id`, so the
// todo side is the only unfiltered read on a page that has no permission gate.
// A plain `where('user_id', ...)` is enough under the #838 null-unit contract:
// a unit-less todo belongs to its creator, so "mine" is the owner predicate
// regardless of `unit_id` — no `orWhereNull` disjunct needed.
//
// Each test builds the SAME fixture pair (one viewer row + one colleague row in
// the viewer's unit), because the assertion that matters is the count: with only
// the viewer row present a leak would be invisible, and with only the colleague
// row present the correct answer and the leak look identical.
beforeEach(function () {
    $this->colleague = Person::create([
        'n_code' => '0987654321',
        'f_name' => 'همکار',
        'l_name' => 'آزمون',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    $this->colleagueUser = User::factory()->create([
        'n_code' => $this->colleague->n_code,
        'password' => Hash::make('password'),
    ]);

    $this->viewerTodo = Todo::factory()->create([
        'title' => 'VIEWER-OWNED-TODO',
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
        'is_completed' => true,
    ]);

    $this->colleagueTodo = Todo::factory()->create([
        'title' => 'COLLEAGUE-OWNED-TODO',
        'unit_id' => $this->unit->id,
        'user_id' => $this->colleagueUser->id,
        'is_completed' => false,
    ]);
});

test('my todos tab renders only the viewer todo of the unit', function () {
    $this->actingAs($this->user);

    Livewire::test('profile.index')
        ->assertSee('VIEWER-OWNED-TODO')
        ->assertDontSee('COLLEAGUE-OWNED-TODO');
});

test('my todos tab queries only the viewer rows', function () {
    $this->actingAs($this->user);

    $todos = Livewire::test('profile.index')->instance()->getUserTodosProperty();

    expect($todos->pluck('user_id')->unique()->all())->toBe([$this->user->id]);
});

test('todo stats count only the viewer rows of the accessible unit', function () {
    $this->actingAs($this->user);

    // Two accessible todos exist; one of them belongs to a colleague.
    Livewire::test('profile.index')
        ->assertSet('totalTodos', 1)
        ->assertSet('completedTodos', 1);
});
