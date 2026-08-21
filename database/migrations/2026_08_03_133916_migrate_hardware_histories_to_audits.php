<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #246 merge: migrate existing `hardware_histories` rows into the new
 * `hardware_audits` table, then drop the old table so there is a single,
 * unified audit trail.
 *
 * The old table has no `source` column, so imported rows default to the
 * legacy marker "web" (they predate source tracking).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hardware_histories') || ! Schema::hasTable('hardware_audits')) {
            return;
        }

        // Copy all old rows into the new unified table.
        DB::statement(
            'INSERT INTO hardware_audits
                (hardware_id, user_id, action, changes, source, ip_address, user_agent, created_at, updated_at)
             SELECT hardware_id, user_id, action, changes, \'web\', ip_address, user_agent, created_at, updated_at
             FROM hardware_histories'
        );

        // Drop the old table now that its data is preserved in hardware_audits.
        Schema::dropIfExists('hardware_histories');
    }

    public function down(): void
    {
        // Cannot reliably restore the dropped table's auto-increment ids; the
        // data is preserved in hardware_audits. Recreate an empty legacy table
        // for structural compatibility if a rollback is ever needed.
        if (! Schema::hasTable('hardware_histories')) {
            Schema::create('hardware_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hardware_id');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action');
                $table->json('changes')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamps();
                $table->index(['hardware_id', 'created_at']);
                $table->index('user_id');
            });
        }
    }
};