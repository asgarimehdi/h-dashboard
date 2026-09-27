<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitType;
use Database\Seeders\PersonUserFromDeviceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(PersonUserFromDeviceSeeder::class);

class PersonUserFromDeviceSeederTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLookupTables();
    }

    public function test_units_of_special_branches_get_their_unit_type(): void
    {
        $this->seed(PersonUserFromDeviceSeeder::class);

        // نام واحد => نوع واحد مورد انتظار (خود واحد و زیرمجموعه‌هایش)
        $expected = [
            'ستاد' => 'ستادی',
            'دبیرخانه' => 'ستادی', // زیرمجموعه مستقیم ستاد
            'بهورزی' => 'ستادی', // زیرمجموعه ستاد
            'فوریت' => 'فوریت',
            'پایگاه فوریت چرگر' => 'فوریت', // زیرمجموعه فوریت
            'مرکز سراج' => 'مرکز روان',
            'پایگاه غیر ضمیمه صائین قلعه' => 'پایگاه سلامت غیر ضمیمه',
        ];

        foreach ($expected as $unitName => $typeName) {
            $unit = Unit::where('name', $unitName)->first();
            $this->assertNotNull($unit, "واحد «{$unitName}» باید ساخته شده باشد");

            $typeId = UnitType::where('name', $typeName)->value('id');
            $this->assertNotNull($typeId, "نوع واحد «{$typeName}» باید ساخته شود");
            $this->assertEquals($typeId, $unit->unit_type_id, "واحد «{$unitName}» باید نوع «{$typeName}» بگیرد");
        }

        // واحدی خارج از این شاخه‌ها نباید نوع بگیرد
        $outside = Unit::where('name', 'مرکز قروه')->first();
        $this->assertNotNull($outside);
        $this->assertNull($outside->unit_type_id, 'واحد خارج از این شاخه‌ها نباید تغییر کند');
    }

    public function test_units_without_region_inherit_the_parent_region(): void
    {
        $county = Region::create(['name' => 'ابهر', 'type' => 'county']);
        $network = Unit::create(['name' => 'شبکه تست', 'region_id' => $county->id]);
        $child = Unit::create(['name' => 'واحد بدون شهرستان', 'parent_id' => $network->id]);
        $grandChild = Unit::create(['name' => 'زیرمجموعه بدون شهرستان', 'parent_id' => $child->id]);

        $this->seed(PersonUserFromDeviceSeeder::class);

        $this->assertEquals($county->id, $child->fresh()->region_id, 'واحد باید شهرستان والدش را ارث ببرد');
        $this->assertEquals($county->id, $grandChild->fresh()->region_id, 'ارث باید چندسطحی هم کار کند');

        // ریشه بدون والد و بدون region نباید city پیدا کند
        $root = Unit::where('name', 'وزارت بهداشت')->first();
        $this->assertNotNull($root);
        $this->assertNull($root->region_id, 'واحد ریشه نباید شهرستان پیدا کند');
    }

    public function test_seeding_is_idempotent_for_the_assigned_unit_types(): void
    {
        $this->seed(PersonUserFromDeviceSeeder::class);
        $this->seed(PersonUserFromDeviceSeeder::class);

        foreach (['ستادی', 'فوریت', 'مرکز روان', 'پایگاه سلامت غیر ضمیمه'] as $typeName) {
            $this->assertSame(
                1,
                UnitType::where('name', $typeName)->count(),
                "اجرای چندباره نباید نوع «{$typeName}» را تکراری بسازد"
            );
        }
    }
}
