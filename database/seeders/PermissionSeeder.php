<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
        Permission::firstOrCreate(['name' => 'kargozini', 'label' => 'کارگزینی']);
        Permission::firstOrCreate(['name' => 'map', 'label' => 'نقشه']);
        Permission::firstOrCreate(['name' => 'organization', 'label' => 'ساختار سازمانی']);
        Permission::firstOrCreate(['name' => 'op-cache', 'label' => 'دسترسی به کش سرور']);
        Permission::firstOrCreate(['name' => 'bw', 'label' => 'آنالیز شبکه']);
        // موارد جدید مربوط به سیستم تیکتینگ
        Permission::firstOrCreate(['name' => 'view_all_tickets', 'label' => 'مشاهده مانیتورینگ کل تیکت‌ها']);
        Permission::firstOrCreate(['name' => 'create_ticket', 'label' => 'ثبت تیکت جدید']);
        Permission::firstOrCreate(['name' => 'manage_unit_tickets', 'label' => 'مدیریت و ارجاع تیکت‌های واحد']);
        Permission::firstOrCreate(['name' => 'view_assigned_tickets', 'label' => 'مشاهده تیکت‌های ارجاع شده به خود']);
        // مربوط به تقویم کارها
        Permission::firstOrCreate(['name' => 'calendar', 'label' => 'تقویم کارها']);
        // مدیریت کاربران و نقش‌ها (فقط مدیر کل)
        Permission::firstOrCreate(['name' => 'manage_users', 'label' => 'مدیریت کاربران']);
        Permission::firstOrCreate(['name' => 'manage_roles', 'label' => 'مدیریت نقش‌ها و دسترسی‌ها']);
        // شناسنامه سخت افزار
        Permission::firstOrCreate(['name' => 'manage_hardware', 'label' => 'شناسنامه سخت افزار']);
        // update cache to know about the newly created permissions (required if using WithoutModelEvents in seeders)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

    }
}
