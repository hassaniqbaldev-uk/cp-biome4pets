<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Breeds lookup table — a managed, reusable suggestion list that powers the pet
 * breed autocomplete (so staff pick "French Bulldog" instead of free-typing
 * "frenchie"). PURELY ADDITIVE: the pets.breed TEXT column is left exactly as-is
 * and no pet row is touched — this table is only a suggestion/dedup list.
 *
 * The `type` column (default 'dog') is included now so a pet-type hierarchy can be
 * added later without a rework, even though nothing populates it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breeds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Forward-compat for a future pet-type hierarchy; nothing reads it yet.
            $table->string('type')->nullable()->default('dog');
            $table->timestamps();

            // Exact-duplicate guard. On MySQL the default *_ci collation makes this
            // case-insensitive too; on SQLite the seed + the Breed model do the
            // case-insensitive dedup in code, so variants never reach the table.
            $table->unique('name');
        });

        $this->seedFromExistingPets();
    }

    public function down(): void
    {
        Schema::dropIfExists('breeds');
    }

    /**
     * Seed the list from whatever breeds already exist on the pets table IN THIS
     * database (live has values local never sees). Deliberately defensive: live
     * data is bigger and messier, so a single odd value must never break the
     * migration.
     *   - Reads pets.breed directly (picks up this DB's real values).
     *   - Trims; skips null/blank/whitespace-only.
     *   - Case-insensitive dedup ("Frenchie" + "frenchie" → one row), keeping the
     *     first-seen casing as the display name.
     *   - Defensively caps absurd lengths so a junk value can't blow the index.
     *   - Idempotent: insertOrIgnore + per-row try/catch, so re-running (or a
     *     value that races a unique clash) is a no-op, never a failure.
     */
    private function seedFromExistingPets(): void
    {
        if (! Schema::hasTable('pets') || ! Schema::hasColumn('pets', 'breed')) {
            return;
        }

        $now = now();
        $seen = [];   // lower(name) => true, for in-PHP case-insensitive dedup

        DB::table('pets')
            ->select('breed')
            ->whereNotNull('breed')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$seen, $now): void {
                foreach ($rows as $row) {
                    try {
                        $name = trim((string) ($row->breed ?? ''));
                        if ($name === '') {
                            continue;                       // skip blank / whitespace-only
                        }
                        if (mb_strlen($name) > 255) {
                            $name = mb_substr($name, 0, 255); // cap junk length
                        }

                        $key = mb_strtolower($name);
                        if (isset($seen[$key])) {
                            continue;                       // case-insensitive dedup
                        }
                        $seen[$key] = true;

                        DB::table('breeds')->insertOrIgnore([
                            'name' => $name,
                            'type' => 'dog',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } catch (\Throwable $e) {
                        // One odd value can never fail the whole migration.
                        Log::warning('Breed seed: skipped a pet breed value', ['error' => $e->getMessage()]);
                    }
                }
            });
    }
};
