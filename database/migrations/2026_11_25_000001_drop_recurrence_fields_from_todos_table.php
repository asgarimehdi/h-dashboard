<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #1783: recurring todos had a full backend (columns, model helpers,
 * nightly generator, schedule) but no product surface could ever create a
 * template — the owner chose removal over building a writer. The generator,
 * its schedule and the model helpers are gone in the same change; this drops
 * the columns they alone used.
 *
 * `template_id` carries the FK/index created by
 * 2026_11_22_000001_add_generation_identity_to_todos_and_tickets.php, so both
 * must go before the column. The old migrations stay as history — they are
 * not edited.
 *
 * `down()` restores the columns with their original definitions (including
 * the FK and index) so a rollback returns the schema to the pre-#1783 shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropForeign('todos_template_fk');
            $table->dropIndex('todos_template_id_idx');
            $table->dropColumn(['recurrence_rule', 'recurrence_interval', 'last_generated_at', 'template_id']);
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->string('recurrence_rule')->default('none')->after('is_completed');
            $table->unsignedInteger('recurrence_interval')->default(1)->after('recurrence_rule');
            $table->timestamp('last_generated_at')->nullable()->after('recurrence_interval');
            $table->unsignedBigInteger('template_id')->nullable()->after('last_generated_at');
            $table->index('template_id', 'todos_template_id_idx');
            $table->foreign('template_id', 'todos_template_fk')
                ->references('id')->on('todos')->onDelete('set null');
        });
    }
};
