<?php

namespace Database\Seeders;

//use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
        Permission::create(['name' => 'kargozini','label'=>'کارگزینی']);
        Permission::create(['name' => 'map','label'=>'نقشه']);
        Permission::create(['name' => 'organization','label'=>'ساختار سازمانی']);
        Permission::create(['name' => 'op-cache','label'=>'دسترسی به کش سرور']);
        // موارد جدید مربوط به سیستم تیکتینگ
        Permission::create(['name' => 'view_all_tickets', 'label' => 'مشاهده مانیتورینگ کل تیکت‌ها']);
        Permission::create(['name' => 'create_ticket', 'label' => 'ثبت تیکت جدید']);
        Permission::create(['name' => 'manage_unit_tickets', 'label' => 'مدیریت و ارجاع تیکت‌های واحد']);
        Permission::create(['name' => 'view_assigned_tickets', 'label' => 'مشاهده تیکت‌های ارجاع شده به خود']);
        // update cache to know about the newly created permissions (required if using WithoutModelEvents in seeders)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();


    }
}
