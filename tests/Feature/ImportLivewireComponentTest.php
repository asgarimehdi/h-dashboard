<?php

namespace Tests\Feature;

use App\Imports\HardwareImport;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

covers(HardwareImport::class);

class ImportLivewireComponentTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);

        $this->unit = Unit::create(['name' => 'واحد تست']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('manage_hardware');
        $this->user->givePermissionTo('kargozini');
        Session::put('current_unit_id', $this->unit->id);
    }

    public function test_hardware_import_reset_form_clears_state(): void
    {
        Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->assertSet('showPreview', false)
            ->call('resetForm')
            ->assertSet('showPreview', false)
            ->assertSet('importStats.total', 0);
    }

    public function test_hardware_import_cancel_import_resets(): void
    {
        Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->set('previewData', [['id' => 1, 'status' => 'create']])
            ->set('showPreview', true)
            ->call('cancelImport')
            ->assertSet('showPreview', false)
            ->assertSet('previewData', []);
    }

    public function test_hardware_import_confirm_without_results_shows_error(): void
    {
        Livewire::actingAs($this->user)
            ->test('hardware.import-hardware.import-hardware')
            ->call('confirmImport')
            ->assertSet('importResults', null);
    }

    public function test_hardware_import_get_selected_actions_maps_statuses(): void
    {
        $component = Livewire::actingAs($this->user)->test('hardware.import-hardware.import-hardware');
        $component->set('previewData', [
            ['id' => 10, 'status' => 'create', 'pc_name' => 'X'],
            ['id' => 11, 'status' => 'update', 'pc_name' => 'Y'],
            ['id' => 12, 'status' => 'unchanged', 'pc_name' => 'Z'],
        ]);

        $method = new ReflectionMethod($component->instance(), 'getSelectedActions');
        $method->setAccessible(true);
        $actions = $method->invoke($component->instance());

        $this->assertEquals('create', $actions['row_2']);
        $this->assertEquals('update', $actions['row_3']);
        $this->assertEquals('skip', $actions['row_4']);
    }

    public function test_persons_import_reset_and_cancel(): void
    {
        Livewire::actingAs($this->user)
            ->test('kargozini.import-persons.import-persons')
            ->set('previewData', [['n_code' => '111', 'status' => 'create']])
            ->set('showPreview', true)
            ->call('cancelImport')
            ->assertSet('showPreview', false)
            ->assertSet('previewData', []);
    }

    public function test_persons_import_get_selected_actions(): void
    {
        $component = Livewire::actingAs($this->user)->test('kargozini.import-persons.import-persons');
        $component->set('previewData', [
            ['n_code' => '111', 'status' => 'create'],
            ['n_code' => '222', 'status' => 'update'],
            ['n_code' => '333', 'status' => 'error'],
        ]);

        $method = new ReflectionMethod($component->instance(), 'getSelectedActions');
        $method->setAccessible(true);
        $actions = $method->invoke($component->instance());

        $this->assertEquals('create', $actions['row_2']);
        $this->assertEquals('update', $actions['row_3']);
        $this->assertEquals('skip', $actions['row_4']);
    }
}
