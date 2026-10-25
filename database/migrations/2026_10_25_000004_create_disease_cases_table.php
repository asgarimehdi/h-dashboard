<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recorded disease cases (#1240, §3.4; decisions 7, 8 and 19).
     *
     * `unit_id` is the registering unit (resolved from the session server
     * side in a later phase); `subject_unit_id` is the patient's unit. All
     * FKs are plain RESTRICT — silently cascading health data away is
     * forbidden.
     *
     * `recorded_at` has no database default: "default today" is application
     * behaviour, and the column is NOT NULL.
     */
    public function up(): void
    {
        Schema::create('disease_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients');
            $table->foreignId('disease_id')->constrained('diseases');
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('subject_unit_id')->constrained('units');
            $table->date('diagnosed_at');
            $table->date('recorded_at');
            $table->jsonb('fields')->default('{}');
            $table->timestamps();

            $table->index(['disease_id', 'subject_unit_id']);
            $table->index('subject_unit_id');
            $table->index('diagnosed_at');
            // Kept alongside the three-column unique below, per §3.4.
            $table->index('patient_id');
            $table->unique(
                ['patient_id', 'disease_id', 'diagnosed_at'],
                'disease_cases_unique_patient_disease_day'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disease_cases');
    }
};
