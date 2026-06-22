<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Security: public reports are served at /report/{token} keyed on a high-entropy
 * random token instead of the guessable petname-sampleid slug (which was
 * enumerable). The slug column stays for admin display only. Backfill any
 * existing rows so nothing 404s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('public_token', 40)->nullable()->unique()->after('slug');
        });

        // Backfill existing reports with a unique token. Uses the query builder
        // (not the Report model) so the migration never depends on the model's
        // scopes/casts — important now that Report has a SoftDeletes global scope
        // that references deleted_at, a column added by a LATER migration.
        DB::table('reports')->whereNull('public_token')->orderBy('id')->pluck('id')
            ->each(function ($id) {
                do {
                    $token = Str::random(40);
                } while (DB::table('reports')->where('public_token', $token)->exists());

                DB::table('reports')->where('id', $id)->update(['public_token' => $token]);
            });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
