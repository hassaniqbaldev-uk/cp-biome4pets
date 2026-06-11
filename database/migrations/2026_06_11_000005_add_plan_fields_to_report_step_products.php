<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_step_products', function (Blueprint $table) {
            $table->string('dose')->nullable()->after('duration');
            $table->string('inclusion')->default('included')->after('dose'); // included | optional
        });

        // Widen quantity to string so it can hold scaffold text such as
        // "3 (one tub per month)". Done in its own statement because change()
        // and add-column should not be mixed in one Blueprint pass.
        Schema::table('report_step_products', function (Blueprint $table) {
            $table->string('quantity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('report_step_products', function (Blueprint $table) {
            $table->dropColumn(['dose', 'inclusion']);
        });

        Schema::table('report_step_products', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable()->change();
        });
    }
};
