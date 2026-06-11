<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_steps', function (Blueprint $table) {
            $table->string('type')->default('product')->after('description'); // product | prose
            $table->string('stage_label')->nullable()->after('type');
            // Prose steps: AI-generated paragraph + optional tip.
            $table->text('body')->nullable()->after('stage_label');
            $table->text('tip')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('report_steps', function (Blueprint $table) {
            $table->dropColumn(['type', 'stage_label', 'body', 'tip']);
        });
    }
};
