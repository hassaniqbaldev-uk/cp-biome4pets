<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Test = one sample analysis (the raw lab/CSV result). Reports are the
        // INTERPRETATION of a Test. Phase 3a: raw lab data moves here; the old
        // report columns are kept for now (dual-write) and dropped in a later step.
        Schema::create('tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // The Test ID == Order ID (entered manually now). sample_id is the lab
            // sample reference (today equal to order_id from the report flow).
            $table->string('order_id');
            $table->string('sample_id');

            $table->date('report_date')->nullable();
            $table->date('collected_at')->nullable();

            // Raw lab data — the CSV and its deterministically parsed metrics.
            $table->string('csv_path')->nullable();
            $table->json('csv_data')->nullable();
            $table->json('phylum_data')->nullable();
            $table->float('diversity_score')->nullable();
            $table->integer('species_richness')->nullable();
            $table->float('dysbiosis_score')->nullable();
            $table->string('microbiome_classification')->nullable();

            // Lifecycle-ready (room for ordered/kit_sent/at_lab later) + future
            // provider keys (Illumina/Shopify/Klaviyo) — empty for now.
            $table->string('status')->default('report_generated');
            $table->json('external_ids')->nullable();

            $table->timestamps();

            // Find-or-create key for "one test per pet + order".
            $table->index(['pet_id', 'order_id']);
        });

        // A report is the interpretation of one test. Nullable + nullOnDelete:
        // existing dummy reports have no test, and deleting a test never deletes
        // its reports.
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('test_id')->nullable()->after('pet_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('test_id');
        });

        Schema::dropIfExists('tests');
    }
};
