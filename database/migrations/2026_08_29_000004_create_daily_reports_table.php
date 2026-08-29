<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->date('report_date');
            $table->text('summary')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();

            $table->foreign('unit_id', 'daily_report_unit_fk')
                ->references('id')->on('units')
                ->onDelete('set null');
            $table->foreign('generated_by', 'daily_report_user_fk')
                ->references('id')->on('users')
                ->onDelete('set null');
            $table->index(['report_date', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
