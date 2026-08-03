<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hardware_audits', function (Blueprint $table) {
            $table->id();
<<<<<<<< HEAD:database/migrations/2026_08_03_121907_create_hardware_histories_table.php
            $table->foreignId('hardware_id')->constrained('hardwares')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // created, updated, deleted, bulk_mark, bulk_delete
            $table->json('changes')->nullable();
========
            // No FK constraint on hardware_id intentionally:
            // the audit trail must survive hardware deletion (ON DELETE CASCADE
            // would wipe the history record along with the hardware).
            $table->unsignedBigInteger('hardware_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // created, updated, deleted, bulk_mark, bulk_delete, rollback
            $table->json('changes')->nullable(); // field-level diff: [{field, old, new}]
            $table->string('source')->default('web'); // web, api, import, bulk
>>>>>>>> origin/246:database/migrations/2026_08_03_003100_create_hardware_audits_table.php
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['hardware_id', 'created_at']);
            $table->index('action');
            $table->index('user_id');
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hardware_audits');
    }
};
