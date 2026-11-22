<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #1640: the nightly writers guarded idempotency by content
 * (`todos.title` + `todos.unit_id`, `tickets.subject` + `tickets.unit_id`), so
 * two same-title templates/schedules in one unit starved each other. These
 * columns record the identity that produced each generated row, so the guards
 * can key on it instead.
 *
 * `onDelete('set null')` is deliberate: deleting a template/schedule must not
 * delete or block its generated history. The side effect — those rows become
 * NULL-identity and re-enter the legacy fallback branch — only ever causes a
 * narrow skip, never a duplicate.
 *
 * No unique index here: a schedule legitimately produces many tickets over its
 * lifetime, and a uniqueness decision needs a cycle column first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->after('recurrence_interval');
            $table->index('template_id', 'todos_template_id_idx');
            $table->foreign('template_id', 'todos_template_fk')
                ->references('id')->on('todos')->onDelete('set null');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable()->after('task_id');
            $table->index('schedule_id', 'tickets_schedule_id_idx');
            $table->foreign('schedule_id', 'tickets_schedule_fk')
                ->references('id')->on('maintenance_schedules')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropForeign('todos_template_fk');
            $table->dropIndex('todos_template_id_idx');
            $table->dropColumn('template_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign('tickets_schedule_fk');
            $table->dropIndex('tickets_schedule_id_idx');
            $table->dropColumn('schedule_id');
        });
    }
};
