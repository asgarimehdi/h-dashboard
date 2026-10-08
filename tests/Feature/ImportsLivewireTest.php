<?php

namespace Tests\Feature;

use App\Imports\HardwareImport;
use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

covers(HardwareImport::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->semat = Semat::create(['name' => 'تکنسین']);
    $this->tahsil = Tahsil::create(['name' => 'لیسانس']);
    $this->estekhdam = Estekhdam::create(['name' => 'رسمی']);
    $this->radif = Radif::create(['name' => 'ردیف 1']);

    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'احمد',
        'l_name' => 'محمدی',
        'u_id' => $this->unit->id,
        's_id' => $this->semat->id,
        't_id' => $this->tahsil->id,
        'e_id' => $this->estekhdam->id,
        'r_id' => $this->radif->id,
    ]);

    $this->user = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->user->givePermissionTo('manage_hardware');
    $this->user->givePermissionTo('kargozini');
    // ایشو #814: import پرسنل داخل کامپوننت هم `manage_personnel` می‌خواهد.
    $this->user->givePermissionTo('manage_personnel');

    Session::put('current_unit_id', $this->unit->id);
});

test('hardware import component shows preview and confirms import', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true)
        ->assertSet('importStats.total', 1)
        ->call('confirmImport');

    $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-NEW']);
});

test('person import component shows preview and confirms import', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true)
        ->assertSet('importStats.total', 1)
        ->call('confirmImport');

    $this->assertDatabaseHas('persons', ['n_code' => '9876543210', 'u_id' => $this->unit->id]);
});

test('import components are protected by RBAC', function () {
    $guestUser = User::factory()->create(); // No permissions

    // Test hardware import route middleware
    $this->actingAs($guestUser)
        ->get('/hardware/import')
        ->assertStatus(403);

    // Test persons import route middleware
    $this->actingAs($guestUser)
        ->get('/kargozini/persons/import')
        ->assertStatus(403);
});

// ایشو #814: لایه‌ی دوم مجوز داخل خودِ کامپوننت — دارنده‌ی صرفِ
// `kargozini` (بدون `manage_personnel`) نه پیش‌نمایش می‌گیرد، نه تایید می‌کند.
test('person import requires manage_personnel inside the component', function () {
    $kargoziniUser = User::factory()->create();
    $kargoziniUser->givePermissionTo('kargozini');

    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    Livewire::actingAs($kargoziniUser)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->assertForbidden();

    Livewire::actingAs($kargoziniUser)
        ->test('kargozini.import-persons.import-persons')
        ->call('confirmImport')
        ->assertForbidden();

    $this->assertDatabaseMissing('persons', ['n_code' => '9876543210']);
});

// ایشو #839 (Plan 50): حساب بدون واحد، `accessibleUnitIds()` خالی می‌گیرد.
// پیش از این پلن، مجوز درست بود اما لایه‌ی اسکوپ زیرش fail-open بود، پس یک
// ایمپورتِ بی‌صدای کل‌سازمان شروع می‌شد. اکنون باید صریح رد شود.
// `beforeEach` یک `current_unit_id` در سشن می‌گذارد و `AccessService` آن
// را به هر کاربری نسبت می‌دهد؛ برای ساختن اسکوپِ واقعاً خالی باید پاک شود.
//
// نکته: `UserFactory` همیشه یک `Person` پشتِ کاربر می‌سازد و آن را به یک
// واحد وصل می‌کند، پس کاربرِ ساخته‌شده با factory **هرگز** اسکوپ خالی ندارد —
// `AccessService` به `person.u_id` می‌رسد. برای بازتولید مسیر `[]` باید
// `u_id` صریحاً `null` شود.
function unitlessUserWithManagePermissions(): User
{
    Session::forget('current_unit_id');

    $user = User::factory()->create(); // factory attaches a Person with a u_id
    $user->person->update(['u_id' => null]); // no user_units row, no person.u_id

    $user->givePermissionTo('manage_personnel');
    $user->givePermissionTo('manage_hardware');

    return $user;
}

/**
 * پیامِ `toast()` در HTML رندر نمی‌شود — `Mary\Traits\Toast` آن را به‌صورت یک
 * **JS effect** (`effects['xjs']` با عبارت `toast({...})`) می‌فرستد، پس
 * `assertSee` همیشه شکست می‌خورد.
 *
 * نکته: عبارتِ JS یک رشته‌ی JSON دِرون‌رشته است، پس متن فارسی داخل آن به
 *‌صورت `\uXXXX` فرار می‌کند. باید `json_decode` شود و بعد مقایسه شود.
 *
 * عنوانِ toast پیدا‌شده را برمی‌گرداند تا فراخوانی بتوانند آن را assert کنند:
 * یک helper که فقط `return` می‌کند هیچ assertion‌ای ثبت نمی‌کند و تست را
 * «risky» (بدون assertion) می‌گذارد.
 */
function assertToastContains(Testable $component, string $needle): string
{
    $expressions = array_column($component->effects['xjs'] ?? [], 'expression');

    foreach ($expressions as $expression) {
        $inner = json_decode(substr($expression, strlen('toast('), -1), true);

        if (is_array($inner) && str_contains($inner['toast']['title'] ?? '', $needle)) {
            return (string) $inner['toast']['title'];
        }
    }

    test()->fail('No toast contained "'.$needle.'"; got: '.implode(' | ', $expressions));
}

test('person import refuses a user with no unit assignment', function () {
    $unitlessUser = unitlessUserWithManagePermissions();

    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    $component = Livewire::actingAs($unitlessUser)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file);

    $component->call('importPreview')
        ->assertSet('showPreview', false)
        ->assertSet('importResults', null);

    assertToastContains($component, 'حساب شما به هیچ واحدی تخصیص نیافته است');

    $this->assertDatabaseMissing('persons', ['n_code' => '9876543210']);
});

test('person import confirm refuses a user with no unit assignment', function () {
    $unitlessUser = unitlessUserWithManagePermissions();

    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    // Force a non-null importResults so the *scope* check, not the
    // "no data" check, is the one that rejects.
    $component = Livewire::actingAs($unitlessUser)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->set('importResults', ['preview' => [], 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'changes' => []])
        ->set('previewData', [['n_code' => '9876543210', 'status' => 'create']]);

    $component->call('confirmImport');

    assertToastContains($component, 'حساب شما به هیچ واحدی تخصیص نیافته است');

    $this->assertDatabaseMissing('persons', ['n_code' => '9876543210']);
});

test('hardware import refuses a user with no unit assignment', function () {
    $unitlessUser = unitlessUserWithManagePermissions();

    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    $component = Livewire::actingAs($unitlessUser)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file);

    $component->call('importPreview')
        ->assertSet('showPreview', false)
        ->assertSet('importResults', null);

    assertToastContains($component, 'حساب شما به هیچ واحدی تخصیص نیافته است');

    $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NEW']);
});

test('hardware import confirm refuses a user with no unit assignment', function () {
    $unitlessUser = unitlessUserWithManagePermissions();

    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    $component = Livewire::actingAs($unitlessUser)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->set('importResults', ['preview' => [], 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'changes' => []])
        ->set('previewData', [['pc_name' => 'PC-NEW', 'status' => 'create']]);

    $component->call('confirmImport');

    assertToastContains($component, 'حساب شما به هیچ واحدی تخصیص نیافته است');

    $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NEW']);
});

// ایشو #872: `confirmImport()` در هر دو کامپوننت، آرایه‌ی `errors` را داخل
// toast موفقیت درج می‌کرد. `HandleExceptions` هر level از `error_reporting(-1)`
// را به `ErrorException` تبدیل می‌کند و `ErrorException extends Exception`،
// پس `catch (\Exception $e)` آن را می‌بلعید — و دو دستورِ بعد از خطِ درج
// یعنی `resetForm()` و `dispatch(...)` هرگز اجرا نمی‌شدند.
//
// نتیجه روی **هر** ایمپورتی، حتی کاملاً موفق: گزارش «خطا در انجام ایمپورت»
// به‌جای toast موفقیت، پیش‌نمایش پاک‌نشده، و رویداد refresh والد که هرگز
// منتشر نشده بود. ردیف‌ها در دیتابیس نوشته می‌شدند، پس تست‌هایی که فقط
// `assertDatabaseHas` می‌کردند سبز می‌ماندند و این مسیر مرده را نمی‌دیدند.
//
// هر سه assert لازم است: assert رویداد، همان چیزی است که این رگرسیون را
// دوباره بی‌صدا برمی‌گرداند، چون `assertDatabaseHas` از آن بی‌خبر می‌ماند.
//
// فیکسچر **بدون خطا** است، چون همین حالت است که امروز می‌شکند؛ یک فیکسچرِ
// خطادار همان catch را به دلیل موجهی اجرا می‌کرد.

test('person import confirm reports success, clears the preview and notifies the parent', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    $component = Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true);

    $component->call('confirmImport')
        // 1. the SUCCESS path, not the catch
        ->assertSet('showPreview', false)
        // 2. resetForm() actually ran
        ->assertSet('importResults', null)
        // 3. the parent-table refresh contract fires
        ->assertDispatched('persons-imported');

    // The SUCCESS toast, asserted as a value so the test is not "risky".
    // The catch branch would read «خطا در انجام ایمپورت: …» instead.
    expect(assertToastContains($component, 'ایمپورت با موفقیت انجام شد'))
        ->toContain('خطا: 0')
        ->not->toContain('Array to string conversion');

    $this->assertDatabaseHas('persons', ['n_code' => '9876543210', 'u_id' => $this->unit->id]);
});

test('hardware import confirm reports success, clears the preview and notifies the parent', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true);

    $component->call('confirmImport')
        ->assertSet('showPreview', false)
        ->assertSet('importResults', null)
        ->assertDispatched('hardware-imported');

    expect(assertToastContains($component, 'ایمپورت با موفقیت انجام شد'))
        ->toContain('خطا: 0')
        ->not->toContain('Array to string conversion');

    $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-NEW']);
});

/**
 * The count must be a NUMBER in the message. `errors` stays an array in
 * `getImportResults()` (it carries the per-row records the stats block
 * renders via `json_encode`), so the components must count it — the same
 * idiom `importStats['errors']` already uses 39 lines above the old bug.
 *
 * A zero-error run prints «خطا: 0»; asserting that exact tail is what proves
 * an int was interpolated rather than an array that happened not to throw.
 */
test('person import confirm counts errors instead of interpolating the array', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    $component = Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview');

    $component->call('confirmImport');

    // «خطا: 0» — an int was interpolated. An array would render as
    // «Array», and before the fix it threw before any message was built.
    expect(assertToastContains($component, 'خطا: 0'))->toBe(
        'ایمپورت با موفقیت انجام شد. جدید: 1, بروزرسانی: 0, خطا: 0'
    );
});

test('hardware import confirm counts errors instead of interpolating the array', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview');

    $component->call('confirmImport');

    expect(assertToastContains($component, 'خطا: 0'))->toBe(
        'ایمپورت با موفقیت انجام شد. جدید: 1, بروزرسانی: 0, خطا: 0'
    );
});

// ایشو #814: همان لایه‌ی دوم برای import سخت‌افزار با مجوزِ گیتِ مسیرش.
test('hardware import requires manage_hardware inside the component', function () {
    $readOnlyUser = User::factory()->create();
    $readOnlyUser->givePermissionTo('kargozini');

    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Livewire::actingAs($readOnlyUser)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->assertForbidden();

    Livewire::actingAs($readOnlyUser)
        ->test('hardware.import-hardware.import-hardware')
        ->call('confirmImport')
        ->assertForbidden();

    $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-NEW']);
});
