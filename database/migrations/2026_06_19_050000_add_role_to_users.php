<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-tier admin access: 'super_admin' can reach the sensitive Settings (API
 * keys / integrations) and Report an Issue pages; 'admin' gets everything else.
 * Defaults to 'admin' so any existing/new row is the lower-privilege role until
 * a Super Admin promotes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('admin')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
