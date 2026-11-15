<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // System-generated tickets (e.g. preventive maintenance) may have no owner.
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // System tickets (maintenance:generate-due) have no owner, so reverting to
        // NOT NULL is only possible once no NULL owner remains. The guard below
        // enforces that instead of trusting the operator.
        $nullRows = DB::table('tickets')->whereNull('user_id')->count();

        if ($nullRows > 0) {
            throw new RuntimeException(
                "Rollback aborted: tickets.user_id has {$nullRows} NULL row(s). "
                .'System tickets written by `maintenance:generate-due` have no owner. '
                .'Reassign them (`UPDATE tickets SET user_id = <admin_id> WHERE user_id IS NULL`) '
                .'or delete them (`DELETE FROM tickets WHERE user_id IS NULL`), then retry.'
            );
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
