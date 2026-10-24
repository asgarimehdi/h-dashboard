<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #1178 — index `attachments.file_path`.
 *
 * `attachments:prune-orphans` matched every file on the public disk against
 * `attachments.file_path`, and the column carried no index of its own (only
 * `ticket_id`, `activity_id` and `user_id` did), so each lookup was a
 * sequential scan of the whole table.
 *
 * The index is deliberately NOT unique: the schema guarantees nothing about
 * one row per path — a file can legitimately be attached to more than one
 * ticket or activity — so a unique index would fail on real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->index('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['file_path']);
        });
    }
};
