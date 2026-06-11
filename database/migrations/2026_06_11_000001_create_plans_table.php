<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('trigger_description')->nullable();
            $table->boolean('enabled')->default(true);
            // Catalogue-level availability. The report builder ignores this for
            // now (the app is dog-only and Pet has no species column), but the
            // field is kept so species filtering can be switched on later.
            $table->string('species_availability')->default('both'); // dog | cat | both
            // Optional steer passed to the copy generator for the "intro".
            $table->text('intro_guidance')->nullable();
            $table->unsignedInteger('position')->default(0);

            // Subscribe-panel data. Display-only: the price is the hardcoded
            // scaffold string; no 20% computation happens here. The render
            // computes/shows a saving from product prices, or uses the label.
            $table->boolean('subscription_available')->default(true);
            $table->string('subscription_price')->nullable();        // e.g. "£35 / month"
            $table->string('subscription_billing_note')->nullable();
            $table->json('subscription_includes')->nullable();       // array of product names
            $table->string('subscription_url')->nullable();          // Shopify subscribe link
            $table->string('subscription_saving_label')->nullable(); // explicit override; else auto

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
