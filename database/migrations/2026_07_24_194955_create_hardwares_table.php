<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardwares', function (Blueprint $table) {
            $table->id();
            $table->string('n_code', 10);
            $table->string('pc_name');
            $table->string('type')->nullable();
            $table->string('os')->nullable();
            $table->string('ip_valid')->nullable();
            $table->string('ip_local')->nullable();
            $table->string('mac')->nullable();
            $table->string('net_type')->nullable();
            $table->string('switch')->nullable();
            $table->string('port')->nullable();
            $table->boolean('shutdown')->default(true);
            $table->string('vlan')->nullable();
            $table->string('motherboard')->nullable();
            $table->string('cpu')->nullable();
            $table->string('ram')->nullable();
            $table->string('hdd')->nullable();
            $table->text('comments')->nullable();
            $table->boolean('mark')->default(false);
            $table->date('clean_at')->nullable();
            $table->timestamps();

            $table->index('n_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardwares');
    }
};
