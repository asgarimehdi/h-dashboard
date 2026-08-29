<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('title');
            // 'daily' | 'weekly' | 'monthly'
            $table->string('frequency')->default('monthly');
            $table->unsignedInteger('recurrence_interval')->default(1);
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->timestamps();

            $table->foreign('unit_id', 'maint_sched_unit_fk')
                ->references('id')->on('units')
                ->onDelete('set null');
            $table->index('next_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_schedules');
    }
};
