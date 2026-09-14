<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much a watcher wants to hear about (Work Item Mentions & Comment Notifications,
 * "Recommended Watch Options").
 *
 * Watching used to be a switch: on the list or not. That conflates two different wishes —
 * "tell me everything that happens here" and "tell me only if it concerns me directly" — and
 * offered no way to say "stop telling me" short of unwatching, which also gives up the thread.
 *
 * A string, not an enum column: `mute` and `mentions` are the two we need today and the set
 * will grow, and adding a value to a MySQL enum is a migration where adding one to a string
 * is not. Existing rows default to `all`, which is exactly what watching meant before this.
 *
 * Portable schema builder only (CLAUDE.md §6) — no DB-specific SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_item_subscribers', function (Blueprint $table) {
            $table->string('level', 16)->default('all')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_item_subscribers', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
