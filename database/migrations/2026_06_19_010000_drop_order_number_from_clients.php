<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the redundant clients.order_number. The order / test reference now lives
 * on the Test entity (tests.order_id == sample_id); nothing reads
 * $client->order_number, so the client-level column is dead weight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('order_number')->nullable()->after('phone');
        });
    }
};
