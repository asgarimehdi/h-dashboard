<?php

namespace Tests\Feature;

use App\Imports\HardwareImport;
use App\Jobs\ImportHardwareJob;
use App\Jobs\ImportPersonsJob;
use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use App\Support\ImportStatus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true);

    expect($component->instance()->previewStats['total'])->toBe(1);

    $component->call('confirmImport');

    $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-NEW']);
});

test('person import component shows preview and confirms import', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    $component = Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true);

    expect($component->instance()->previewStats['total'])->toBe(1);

    $component->call('confirmImport');

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
    // "no data" check, is the one that rejects. The scope gate runs before
    // the cached-preview lookup, so no real preview token is needed.
    $component = Livewire::actingAs($unitlessUser)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->set('importResults', ['created' => 0, 'updated' => 0, 'skipped' => 0]);

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
        ->set('importResults', ['created' => 0, 'updated' => 0, 'skipped' => 0]);

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

// ایشو #1167: confirm دیگر هم‌زمان نیست — فایل ذخیره و job صف می‌شود. پیام
// باید وضعیت واقعی («در حال انجام») را بگوید، نه موفقیتی که هنوز رخ نداده.
// assertهای دیتابیس با `QUEUE_CONNECTION=sync` سبز می‌مانند (job همان‌جا اجرا
// می‌شود)، ولی این پیام است که قرارداد async را قفل می‌کند.
//
// ایشو #1194: `persons-imported` دیگر در لحظهٔ dispatch منتشر نمی‌شود — آن
// لحظه هنوز هیچ ردیفی وجود ندارد و جدول والد باید بعد از پایان واقعی
// ایمپورت refresh شود. اینجا قفل می‌شود که هنوز منتشر نشده، و پایین‌تر
// (تست `checkImportStatus`) قفل می‌شود که بعد از انتشار نتیجه منتشر می‌شود.
test('person import confirm queues the import, clears the preview and notifies the parent', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    $component = Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->assertSet('showPreview', true);

    $component->call('confirmImport')
        // 1. the queued path, not the catch
        ->assertSet('showPreview', false)
        // 2. resetForm() actually ran
        ->assertSet('importResults', null)
        // 3. the parent-table refresh must NOT fire before the rows exist
        ->assertNotDispatched('persons-imported')
        // 4. the import is marked as running
        ->assertSet('importRunning', true);

    expect(assertToastContains($component, 'ایمپورت در حال انجام است'))
        ->not->toContain('Array to string conversion');

    $this->assertDatabaseHas('persons', ['n_code' => '9876543210', 'u_id' => $this->unit->id]);
});

test('hardware import confirm queues the import, clears the preview and notifies the parent', function () {
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
        // #1194: the parent table must not be refreshed before the rows exist.
        ->assertNotDispatched('hardware-imported')
        ->assertSet('importRunning', true);

    expect(assertToastContains($component, 'ایمپورت در حال انجام است'))
        ->not->toContain('Array to string conversion');

    $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-NEW']);
});

/**
 * #872 pinned that `errors` (an array) was never interpolated into a toast.
 * #1167 removed the counts from the confirm toast entirely, so the regression
 * now has a narrower shape: confirm must queue exactly one job and never fall
 * into the catch branch. The payload's `selectedActions` is the observable
 * contract that the preview state was read before `resetForm()` cleared it.
 */
test('person import confirm reads the preview actions before resetting the form', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview');

    $component->call('confirmImport');

    Queue::assertPushed(ImportPersonsJob::class, function (ImportPersonsJob $job) {
        return $job->selectedActions === ['row_2' => 'create'];
    });
});

test('hardware import confirm reads the preview actions before resetting the form', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview');

    $component->call('confirmImport');

    Queue::assertPushed(ImportHardwareJob::class, function (ImportHardwareJob $job) {
        return $job->selectedActions === ['row_2' => 'create'];
    });
});

/**
 * #1194: the parent table was refreshed at dispatch time — before the worker
 * had run, so before a single row existed. The imported devices then stayed
 * invisible until a manual reload.
 *
 * The fix: the job publishes its result to the cache and the component polls
 * for it; only then is the parent notified. These two tests pin both halves
 * of that contract: nothing is dispatched while the import is still running,
 * and the event fires once the result is there.
 */
test('hardware import notifies the parent only after the queued job published its result', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    // The job must not run inside the request — that is the whole point of
    // the queue. `Queue::fake()` keeps it pending.
    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->call('confirmImport')
        ->assertSet('importRunning', true)
        // The rows do not exist yet, so the parent must not be refreshed.
        ->assertNotDispatched('hardware-imported');

    // Poll while the worker has not reported back: still nothing.
    $component->call('checkImportStatus')
        ->assertSet('importRunning', true)
        ->assertNotDispatched('hardware-imported');

    // The worker finished and published the result.
    $token = $component->get('statusToken');
    ImportStatus::publish($this->user->id, $token, ['created' => 1, 'updated' => 0, 'skipped' => 0, 'errors' => []]);

    $component->call('checkImportStatus')
        // Now the rows exist, so the parent is refreshed.
        ->assertDispatched('hardware-imported')
        ->assertSet('importRunning', false)
        ->assertSet('statusToken', null)
        ->assertSet('importStats.new', 1)
        ->assertSet('importStats.updated', 0);

    expect(assertToastContains($component, 'ایمپورت تکمیل شد'))
        ->toContain('1 ایجاد');
});

test('person import notifies the parent only after the queued job published its result', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->call('confirmImport')
        ->assertSet('importRunning', true)
        ->assertNotDispatched('persons-imported');

    $component->call('checkImportStatus')
        ->assertSet('importRunning', true)
        ->assertNotDispatched('persons-imported');

    $token = $component->get('statusToken');
    ImportStatus::publish($this->user->id, $token, ['created' => 0, 'updated' => 1, 'skipped' => 0, 'errors' => []]);

    $component->call('checkImportStatus')
        ->assertDispatched('persons-imported')
        ->assertSet('importRunning', false)
        ->assertSet('importStats.updated', 1);
});

/**
 * #1194: a failed import left the browser waiting forever — the component
 * had dispatched "in progress" and nothing ever told it that the job died.
 */
test('a failed hardware import tells the user and frees the import slot', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->call('confirmImport');

    $token = $component->get('statusToken');
    ImportStatus::publishFailure($this->user->id, $token, 'کارمند تکراری');

    $component->call('checkImportStatus')
        ->assertSet('importRunning', false)
        ->assertSet('statusToken', null)
        // A failed import must not pretend the parent table needs a refresh.
        ->assertNotDispatched('hardware-imported');

    expect(assertToastContains($component, 'ایمپورت ناموفق بود'))
        ->toContain('کارمند تکراری');
});

/**
 * #1194: a double click queued the same sheet twice. Both jobs compared
 * against the pre-import state, so the second one created rows the first
 * had already created. The claim is atomic, so the second click is rejected
 * with a message and no second job is queued.
 */
test('a second hardware import is refused while one is still running', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->call('confirmImport')
        ->assertSet('importRunning', true);

    // The preview is gone after the first confirm, so the second click goes
    // through the same path with a fresh preview.
    $file2 = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);
    $component->set('file', $file2)
        ->call('importPreview')
        ->call('confirmImport');

    Queue::assertPushed(ImportHardwareJob::class, 1);

    expect(assertToastContains($component, 'یک ایمپورت در حال انجام است'))
        ->not->toBeEmpty();
});

test('a second person import is refused while one is still running', function () {
    $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
    $csvContent .= "9876543210\tعلی\tرضایی\t{$this->tahsil->id}\t{$this->estekhdam->id}\t{$this->semat->id}\t{$this->radif->id}\t".$this->unit->id."\n";
    $file = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);

    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('kargozini.import-persons.import-persons')
        ->set('file', $file)
        ->call('importPreview')
        ->call('confirmImport')
        ->assertSet('importRunning', true);

    $file2 = UploadedFile::fake()->createWithContent('persons.csv', $csvContent);
    $component->set('file', $file2)
        ->call('importPreview')
        ->call('confirmImport');

    Queue::assertPushed(ImportPersonsJob::class, 1);

    expect(assertToastContains($component, 'یک ایمپورت در حال انجام است'))
        ->not->toBeEmpty();
});

/**
 * #1194: the per-row errors of a finished import were surfaced nowhere — the
 * browser only ever saw the "in progress" toast, so a sheet that failed on
 * some rows looked exactly like one that succeeded.
 */
test('a finished import surfaces its per-row errors', function () {
    $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
    $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t11:22:33:44:55:66\n";
    $file = UploadedFile::fake()->createWithContent('hardware.csv', $csvContent);

    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test('hardware.import-hardware.import-hardware')
        ->set('file', $file)
        ->call('importPreview')
        ->call('confirmImport');

    $token = $component->get('statusToken');
    ImportStatus::publish($this->user->id, $token, [
        'created' => 0,
        'updated' => 0,
        'skipped' => 1,
        'errors' => [
            ['row' => 7, 'error' => 'Person 1234567890 not found', 'data' => []],
        ],
    ]);

    $component->call('checkImportStatus')
        ->assertSet('importStats.errors', 1)
        ->assertDispatched('hardware-imported');

    expect(assertToastContains($component, 'Person 1234567890 not found'))
        ->toContain('ردیف 7');
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
