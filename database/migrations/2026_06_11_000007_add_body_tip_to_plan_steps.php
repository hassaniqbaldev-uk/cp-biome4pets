<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prose copy (body/tip) is normally generated at report time, so it was not
     * part of the original plan_steps scaffold. These optional columns let an
     * admin store a template/override on a prose step from the Plans builder.
     */
    public function up(): void
    {
        Schema::table('plan_steps', function (Blueprint $table) {
            $table->text('body')->nullable()->after('stage_label');
            $table->text('tip')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('plan_steps', function (Blueprint $table) {
            $table->dropColumn(['body', 'tip']);
        });
    }
};
