<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL needs ALTER TABLE to modify ENUM values
        // SQLite ignores ENUM constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tickets MODIFY COLUMN priority ENUM('urgent','high','normal','medium','low') NOT NULL DEFAULT 'normal'");
        }

        // PostgreSQL stores the enum as text with a CHECK constraint generated
        // by Laravel's $table->enum(). Replace that constraint to allow the new
        // 'medium' (and 'high') values.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_priority_check');
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority::text IN ('urgent','high','normal','medium','low'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tickets MODIFY COLUMN priority ENUM('urgent','normal','low') NOT NULL DEFAULT 'normal'");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_priority_check');
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority::text IN ('urgent','normal','low'))");
        }
    }
};
