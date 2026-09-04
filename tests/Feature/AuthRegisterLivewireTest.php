<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the auth.register Livewire component.
 * Covers mount redirect when authenticated, successful registration
 * (user creation, login, redirect), validation errors,
 * missing person, and duplicate user.
 *
 * NOTE: The component calls request()->session()->regenerate() which fails
 * in Livewire test mode because Livewire tests skip the StartSession
 * middleware via withoutMiddleware(). We work around this by wrapping the
 * HTTP kernel to attach the session store to every request it handles.
 */
class AuthRegisterLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed lookup tables required by Person FKs.
        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        // Reset the sequences so future inserts don't collide with explicit ids.
        DB::select("SELECT setval('tahsils_id_seq', COALESCE((SELECT MAX(id) FROM tahsils), 1))");
        DB::select("SELECT setval('estekhdams_id_seq', COALESCE((SELECT MAX(id) FROM estekhdams), 1))");
        DB::select("SELECT setval('semats_id_seq', COALESCE((SELECT MAX(id) FROM semats), 1))");
        DB::select("SELECT setval('radifs_id_seq', COALESCE((SELECT MAX(id) FROM radifs), 1))");

        // Livewire tests call withoutMiddleware(), which skips StartSession.
        // That means the request inside the kernel never gets a session store
        // attached, so request()->session() throws RuntimeException.
        //
        // Fix: wrap the kernel's handle() to attach the session store to
        // every request before it reaches the (middleware-less) pipeline.
        $this->app->extend(Kernel::class, function ($kernel) {
            $app = $this->app;

            return new class($kernel, $app) implements Kernel
            {
                public function __construct(
                    private $inner,
                    private $app,
                ) {}

                public function handle($request)
                {
                    if (! $request->hasSession() && $this->app->bound('session.store')) {
                        $request->setLaravelSession($this->app->make('session.store'));
                    }

                    return $this->inner->handle($request);
                }

                public function terminate($request, $response): void
                {
                    $this->inner->terminate($request, $response);
                }

                public function bootstrap(): void
                {
                    $this->inner->bootstrap();
                }

                public function getMiddleware(): array
                {
                    return $this->inner->getMiddleware();
                }

                public function hasMiddleware($middleware): bool
                {
                    return $this->inner->hasMiddleware($middleware);
                }

                public function prependMiddlewareToStack($middleware): void
                {
                    $this->inner->prependMiddlewareToStack($middleware);
                }

                public function appendMiddlewareToStack($middleware): void
                {
                    $this->inner->appendMiddlewareToStack($middleware);
                }

                public function isMiddlewareCached(): bool
                {
                    return $this->inner->isMiddlewareCached();
                }

                public function shouldSkipMiddleware(): bool
                {
                    return $this->inner->shouldSkipMiddleware();
                }

                public function terminating($callback): void
                {
                    $this->inner->terminating($callback);
                }

                public function getApplication(): Application
                {
                    return $this->inner->getApplication();
                }
            };
        });
    }

    /**
     * Create a Person row that the register component can look up.
     */
    protected function createPerson(string $nCode = '1234567890'): Person
    {
        return Person::create([
            'n_code' => $nCode,
            'f_name' => 'تست',
            'l_name' => 'کاربر',
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => DB::table('units')->insertGetId(['name' => 'واحد تست']),
        ]);
    }

    public function test_guest_renders(): void
    {
        Livewire::test('auth.register')
            ->assertOk()
            ->assertSee('ثبت نام');
    }

    public function test_authed_redirects(): void
    {
        $person = $this->createPerson();
        $user = User::factory()->create(['n_code' => $person->n_code]);

        $this->actingAs($user);

        Livewire::test('auth.register')
            ->assertRedirect('/');
    }

    public function test_registers_valid(): void
    {
        $this->createPerson('1234567890');

        Livewire::test('auth.register')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertDatabaseHas('users', ['n_code' => '1234567890']);
        $this->assertAuthenticatedAs(
            User::where('n_code', '1234567890')->first()
        );
    }

    public function test_session_regenerated(): void
    {
        $this->createPerson('1234567890');

        $sessionBefore = session()->token();

        Livewire::test('auth.register')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->call('register');

        // After a successful registration the session token should have changed,
        // confirming that session()->regenerate() was called inside the component.
        $this->assertNotEquals($sessionBefore, session()->token());
    }

    public function test_validation_errors(): void
    {
        // Empty fields
        Livewire::test('auth.register')
            ->call('register')
            ->assertHasErrors(['n_code', 'password']);

        // n_code too short
        Livewire::test('auth.register')
            ->set('n_code', '12345')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->call('register')
            ->assertHasErrors(['n_code']);

        // Passwords don't match
        Livewire::test('auth.register')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'different')
            ->call('register')
            ->assertHasErrors(['password']);
    }

    public function test_person_missing(): void
    {
        // No person with this n_code exists in the database.
        Livewire::test('auth.register')
            ->set('n_code', '9999999999')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->call('register')
            ->assertHasErrors('n_code');

        $this->assertDatabaseMissing('users', ['n_code' => '9999999999']);
    }

    public function test_user_duplicate(): void
    {
        $this->createPerson('1234567890');
        User::factory()->create(['n_code' => '1234567890']);

        Livewire::test('auth.register')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->call('register')
            ->assertHasErrors('n_code');

        // Ensure only one user exists for this n_code.
        $this->assertEquals(1, User::where('n_code', '1234567890')->count());
    }
}
