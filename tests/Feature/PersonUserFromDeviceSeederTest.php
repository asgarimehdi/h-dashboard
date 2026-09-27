<?php

namespace Tests\Feature;

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

    public function test_units_inside_setad_get_the_setad_unit_type(): void
    {
        $this->seed(PersonUserFromDeviceSeeder::class);

        $typeId = UnitType::where('name', 'ستادی')->value('id');
        $this->assertNotNull($typeId, 'نوع واحد «ستادی» باید ساخته شود');

        // خود «ستاد»، یک زیرمجموعه مستقیم و یک واحد عمیق‌تر
        foreach (['ستاد', 'دبیرخانه', 'بهورزی'] as $name) {
            $unit = Unit::where('name', $name)->first();
            $this->assertNotNull($unit, "واحد «{$name}» باید ساخته شده باشد");
            $this->assertEquals($typeId, $unit->unit_type_id, "واحد «{$name}» باید نوع «ستادی» بگیرد");
        }

        // واحد خارج از ستاد نباید نوع بگیرد
        $outside = Unit::where('name', 'فوریت')->first();
        $this->assertNotNull($outside);
        $this->assertNull($outside->unit_type_id, 'واحد خارج از ستاد نباید تغییر کند');
    }

    public function test_seeding_is_idempotent_for_the_setad_type(): void
    {
        $this->seed(PersonUserFromDeviceSeeder::class);
        $this->seed(PersonUserFromDeviceSeeder::class);

        $this->assertSame(
            1,
            UnitType::where('name', 'ستادی')->count(),
            'اجرای چندباره نباید نوع واحد تکراری بسازد'
        );
    }
}
