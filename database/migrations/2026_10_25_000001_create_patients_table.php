<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disease-registration patients (#1240).
     *
     * Deliberately separate from `persons` (decision 4): a patient is a
     * registry entry keyed by national code, not an HR record. The only link
     * between the two is a possibly-equal `kod_melli` value; there is no FK
     * in either direction. No soft deletes in v1.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('kod_melli', 10)->unique();
            $table->string('first_name');
            $table->string('last_name');
            // Stored in Gregorian; the Jalali picker is UI work.
            $table->date('birth_date')->nullable();
            // `male`/`female` only — app-level validation, no DB CHECK.
            $table->string('gender');
            $table->string('city')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
