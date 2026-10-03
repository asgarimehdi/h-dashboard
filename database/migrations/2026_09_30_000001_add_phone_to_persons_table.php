<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a free-text phone number to persons (#738).
     *
     * String, nullable, no format validation — the decided contract is a
     * plain max-length rule in the form, not a regex constraint: real-world
     * Iranian numbers arrive as "0912…", "+98…", "0912 345 6789", with
     * separators, and a strict pattern would silently reject valid rows.
     */
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('u_id');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
