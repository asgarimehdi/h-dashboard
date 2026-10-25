<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-disease dynamic field definitions (#1240, §3.3).
     *
     * This is the only CASCADE foreign key in phase 1: a field definition
     * belongs to its disease and has no meaning without it.
     */
    public function up(): void
    {
        Schema::create('disease_field_defs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disease_id')->constrained('diseases')->cascadeOnDelete();
            $table->string('field_key');
            $table->string('label_fa');
            $table->enum('type', ['number', 'text', 'select', 'date']);
            $table->string('unit')->nullable();
            // `json`, not `jsonb` (§3.3) — only populated for select fields.
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->integer('sort')->default(0);
            // Gate addition (#1242): lets §8.4 hide a field from new forms.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['disease_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disease_field_defs');
    }
};
