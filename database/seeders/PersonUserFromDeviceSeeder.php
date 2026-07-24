<?php

namespace Database\Seeders;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class PersonUserFromDeviceSeeder extends Seeder
{
    private array $locationCache = [];
    private array $sematCache = [];
    private array $radifCache = [];

    private array $roleMap = [
        'پزشک' => 'expert',
        'دندانپزشک' => 'expert',
        'ماما' => 'expert',
        'مدیریت' => 'unit_manager',
        'ریاست' => 'unit_manager',
        'معاونت بهداشت' => 'unit_manager',
    ];

    // Map raw devices.unit values to proper semat names
    private array $sematMap = [
        'پزشک' => 'پزشک',
        'دندانپزشک' => 'دندانپزشک',
        'ماما' => 'ماما',
        'بهورز' => 'بهورز',
        'بهورزی' => 'بهورز',
        'مراقب سلامت' => 'مراقب سلامت',
        'ناظر' => 'ناظر',
        'مراقب ناظر' => 'مراقب ناظر',
        'بهداشت محیط' => 'بهداشت محیط',
        'بهداشت حرفه ای' => 'بهداشت حرفه ای',
        'بهداشت خانواده' => 'بهداشت خانواده',
        'بهداشت مدارس' => 'بهداشت مدارس',
        'سلامت روان' => 'بهداشت روان',
        'روان' => 'بهداشت روان',
        'تغذیه' => 'تغذیه',
        'واکسیناسیون' => 'واکسیناسیون',
        'آزمایشگاه' => 'آزمایشگاه',
        'داروخانه' => 'داروخانه',
        'پرستاری' => 'پرستاری',
        'مشاوره ازدواج' => 'مشاوره',
        'مشاوره اعتیاد' => 'مشاوره',
        'مشاوره رفتاری' => 'مشاوره',
        'ژنتیک' => 'ژنتیک',
        'پذیرش' => 'پذیرش',
        'فوریت' => 'فوریت',
        'آی تی' => 'کارشناس آی تی',
        'حسابدار' => 'حسابدار',
        'اسناد' => 'اسناد',
        'بایگانی' => 'بایگانی',
        'اموال' => 'اموال',
        'امور حقوقی' => 'امور حقوقی',
        'امور عمومی' => 'امور عمومی',
        'انبار' => 'انبار',
        'انبار دارویی' => 'انبار دارویی',
        'بحران' => 'بحران',
        'تدارکات' => 'تدارکات',
        'تجهیزات پزشکی' => 'تجهیزات پزشکی',
        'حراست' => 'حراست',
        'دبیرخانه' => 'دبیرخانه',
        'دفتر فنی' => 'دفتر فنی',
        'دفتر مدیریت' => 'دفتر مدیریت',
        'روابط عمومی' => 'روابط عمومی',
        'گزینش' => 'گزینش',
        'گسترش' => 'گسترش',
        'نگهبانی' => 'نگهبانی',
        'نظارت بر درمان' => 'نظارت بر درمان',
        'مدیریت' => 'مدیریت',
        'ریاست' => 'ریاست',
        'معاونت بهداشت' => 'معاونت بهداشت',
        'آموزش سلامت' => 'آموزش سلامت',
        'مربی' => 'مربی',
        'کارگزینی' => 'کارگزینی',
        'غذا و دارو' => 'غذا و دارو',
        'تایمکس' => 'تایمکس',
        'بیماری واگیر' => 'بیماری واگیر',
        'بیماریهای واگیر' => 'بیماری واگیر',
        'بیماریهای غیر واگیر' => 'بیماریهای غیر واگیر',
        'خدمات' => 'خدمات',
        'شخصی' => 'شخصی',
        'دیده وری' => 'دیده وری',
        'کلاس' => 'کلاس',
    ];

    // Location/hardware names that should NOT be semat — map to "سایر"
    private array $invalidSemat = [
        'دکل قروه به قروه', 'قروه', 'قروه به شبکه', 'شبکه به قروه',
        'هفده شهریور', 'هیدج', 'عمیدآباد', 'اعلایی', 'شناط', 'درسجین',
        'شریف آباد', 'حسین آباد', 'صائین قلعه', 'مرکز 5',
        'شبکه به شناط', 'شبکه به صائین', 'شبکه به مرکز5', 'شبکه به بهورزی',
        'شبکه به اعلایی حسین آباد 17شهریور', 'شبکه به درسجین قروه شریف آباد',
        'صائین قلعه به شبکه', 'صائین قلعه به عمیدآباد', 'صائین قلعه به هیدج',
        'پایگاه 3 هفده شهریور', 'اعلایی', 'کوه زین', 'سراج',
    ];

    public function run(): void
    {
        $devices = DB::connection('hwinfo')
            ->table('devices')
            ->whereNotNull('n_code')
            ->where('n_code', '!=', '')
            ->get();

        $grouped = $devices->groupBy('n_code');
        $existingUnits = Unit::pluck('id', 'name')->toArray();

        // Preload existing semat and radif
        foreach (DB::table('semats')->get() as $row) {
            $this->sematCache[$row->name] = $row->id;
        }
        foreach (DB::table('radifs')->get() as $row) {
            $this->radifCache[$row->name] = $row->id;
        }

        foreach ($grouped as $nCode => $records) {
            if (Person::where('n_code', $nCode)->exists()) {
                continue;
            }

            // Name
            $operatorName = $records->firstWhere('operator_name', '!=', '')?->operator_name ?? null;
            if ($operatorName) {
                $parts = $this->parseName($operatorName);
            } else {
                $record = $records->first();
                $generated = trim(($record->unit ?? '') . ' ' . ($record->location_type ?? '') . ' ' . ($record->location ?? ''));
                $parts = $this->parseName($generated ?: 'کاربر ناشناس');
            }

            // Primary location → unit
            $primaryLocation = $records->pluck('location')
                ->filter(fn($loc) => $loc && trim($loc) !== '')
                ->countBy()->sortDesc()->keys()->first() ?? '';
            $unitId = $this->matchOrCreateUnit(trim($primaryLocation), $existingUnits);

            // Primary unit type → semat + role
            $primaryUnitType = $records->pluck('unit')
                ->filter(fn($u) => $u && trim($u) !== '')
                ->countBy()->sortDesc()->keys()->first() ?? '';
            $roleName = $this->mapRole($primaryUnitType);
            $sematName = $this->mapSemat($primaryUnitType);
            $sematId = $this->findOrCreateSemat($sematName);

            // radif = semat (same value for now)
            $radifId = $this->findOrCreateRadif($sematName);

            Person::create([
                'n_code' => $nCode,
                'f_name' => $parts['f_name'],
                'l_name' => $parts['l_name'] ?? '',
                't_id' => 1,
                'e_id' => 1,
                's_id' => $sematId,
                'r_id' => $radifId,
                'u_id' => $unitId,
            ]);

            $user = User::create([
                'n_code' => $nCode,
                'password' => Hash::make('12345678'),
            ]);

            $user->units()->attach($unitId, [
                'role' => 'staff',
                'is_primary' => true,
            ]);

            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $user->assignRole($role);
            }
        }
    }

    private function parseName(string $name): array
    {
        $name = trim($name);
        $parts = preg_split('/\s+/', $name, 2);
        return [
            'f_name' => $parts[0] ?? $name,
            'l_name' => $parts[1] ?? '',
        ];
    }

    private function matchOrCreateUnit(string $location, array &$existingUnits): int
    {
        if ($location === '') {
            return 5;
        }
        if (isset($this->locationCache[$location])) {
            return $this->locationCache[$location];
        }
        if (isset($existingUnits[$location])) {
            $this->locationCache[$location] = $existingUnits[$location];
            return $existingUnits[$location];
        }
        foreach ($existingUnits as $unitName => $unitId) {
            if (str_contains($unitName, $location) || str_contains($location, $unitName)) {
                $this->locationCache[$location] = $unitId;
                return $unitId;
            }
        }
        $newUnit = Unit::create(['name' => $location, 'is_active' => true]);
        $existingUnits[$location] = $newUnit->id;
        $this->locationCache[$location] = $newUnit->id;
        return $newUnit->id;
    }

    private function mapSemat(string $rawUnit): string
    {
        $rawUnit = trim($rawUnit);

        if ($rawUnit === '' || in_array($rawUnit, $this->invalidSemat, true)) {
            return 'سایر';
        }

        return $this->sematMap[$rawUnit] ?? 'سایر';
    }

    private function findOrCreateSemat(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 1;
        }
        if (isset($this->sematCache[$name])) {
            return $this->sematCache[$name];
        }
        $id = DB::table('semats')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->sematCache[$name] = $id;
        return $id;
    }

    private function findOrCreateRadif(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 1;
        }
        if (isset($this->radifCache[$name])) {
            return $this->radifCache[$name];
        }
        $id = DB::table('radifs')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->radifCache[$name] = $id;
        return $id;
    }

    private function mapRole(string $unitType): string
    {
        $unitType = trim($unitType);
        return $this->roleMap[$unitType] ?? 'user';
    }
}
