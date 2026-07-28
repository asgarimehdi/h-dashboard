<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL needs ALTER TABLE to modify ENUM values
        // SQLite ignores ENUM constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tickets MODIFY COLUMN priority ENUM('urgent','high','normal','medium','low') NOT NULL DEFAULT 'normal'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tickets MODIFY COLUMN priority ENUM('urgent','normal','low') NOT NULL DEFAULT 'normal'");
        }
    }
};