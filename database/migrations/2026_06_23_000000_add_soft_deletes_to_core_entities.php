<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data safety: soft deletes on the core Client → Pet → Test → Report chain, so an
 * accidental admin delete is RECOVERABLE rather than permanent. Each table gets a
 * nullable deleted_at; a normal delete now only stamps it (the row survives and
 * can be restored), and only an explicit force-delete removes it for good.
 */
return new class extends Migration
{
    private const TABLES = ['clients', 'pets', 'tests', 'reports'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropSoftDeletes();
            });
        }
    }
};
