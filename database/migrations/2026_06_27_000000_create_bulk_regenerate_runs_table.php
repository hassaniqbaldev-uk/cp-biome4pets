<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists each bulk-regeneration run so it survives a closed tab. The in-browser
 * chunked processor (BulkRegenerateReports) writes its progress here every chunk,
 * so a run is recoverable / resumable and surfaceable on the dashboard. Additive,
 * safe on live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_regenerate_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total')->default(0);
            // The full batch + the ids still to process (done = batch − remaining).
            $table->json('batch_ids');
            $table->json('remaining_ids');
            $table->unsignedInteger('regenerated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('needs_review_count')->default(0);
            // running | completed | interrupted | cancelled
            $table->string('status')->default('running')->index();
            // Heartbeat: bumped every chunk. A 'running' row whose heartbeat is
            // stale (browser stopped polling) is inferred as interrupted.
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // Set when the admin dismisses a completed run's dashboard card.
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_regenerate_runs');
    }
};
