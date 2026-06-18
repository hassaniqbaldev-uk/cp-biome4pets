<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3d: the Test now owns the raw lab data outright. The report's own raw
 * columns have been dual-written this whole time but are no longer read (the
 * Report→Test proxy resolves them). Drop them; every read now resolves to the
 * linked Test via Report::TEST_PROXY_FIELDS.
 */
return new class extends Migration
{
    /**
     * The raw lab columns the report mirrored from its Test. Dropped here.
     */
    private array $columns = [
        'sample_id',
        'report_date',
        'csv_path',
        'csv_data',
        'phylum_data',
        'diversity_score',
        'species_richness',
        'dysbiosis_score',
        'microbiome_classification',
    ];

    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // sample_id/report_date were originally NOT NULL, but existing rows
            // would have no value to backfill, so recreate them nullable.
            $table->string('sample_id')->nullable();
            $table->date('report_date')->nullable();
            $table->string('csv_path')->nullable();
            $table->json('csv_data')->nullable();
            $table->json('phylum_data')->nullable();
            $table->float('diversity_score')->nullable();
            $table->integer('species_richness')->nullable();
            $table->float('dysbiosis_score')->nullable();
            $table->string('microbiome_classification')->nullable();
        });
    }
};
