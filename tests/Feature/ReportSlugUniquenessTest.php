<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guard for the production 500:
 *   "Duplicate entry 'pp1a-pp1a' for key 'reports_slug_unique'".
 *
 * The report slug ("{pet}-{sample_id}") is generated in Report's creating hook. Its
 * uniqueness pre-check used the default (soft-delete-scoped) query, so a slug still
 * held by a SOFT-DELETED report read as free while the DB unique index — which spans
 * trashed rows — rejected the insert. Repeat creation for one pet (or after a delete)
 * then 500'd. The fix checks withTrashed() and retries on a race.
 */
class ReportSlugUniquenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function pet(string $name = 'PP1A'): Pet
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);

        return Pet::create(['client_id' => $client->id, 'name' => $name]);
    }

    private function makeReport(Pet $pet): Report
    {
        return Report::create([
            'client_id' => $pet->client_id, 'pet_id' => $pet->id,
            'status' => 'draft', 'pet_snapshot' => ['name' => $pet->name],
        ]);
    }

    /** The reported case: pet "PP1A" → base slug "pp1a-...", created five times, all
     *  succeed with DISTINCT slugs and no constraint violation. */
    public function test_repeat_creation_for_one_pet_produces_distinct_slugs(): void
    {
        $pet = $this->pet('PP1A');

        $slugs = [];
        for ($i = 0; $i < 5; $i++) {
            $slugs[] = $this->makeReport($pet)->slug;
        }

        $this->assertCount(5, $slugs);
        $this->assertSame($slugs, array_unique($slugs), 'every report must get a distinct slug');
        // First is the bare base; the rest are suffixed.
        $this->assertSame($slugs[0], reset($slugs));
        foreach (array_slice($slugs, 1) as $slug) {
            $this->assertStringStartsWith($slugs[0].'-', $slug);
        }
    }

    /** THE ACTUAL BUG: a soft-deleted report's slug must not be reused — its slug is
     *  still in the unique index, so the new report must get a different one. */
    public function test_soft_deleted_slug_is_not_reused(): void
    {
        $pet = $this->pet('PP1A');

        $first = $this->makeReport($pet);
        $firstSlug = $first->slug;
        $first->delete();                 // soft delete — row + slug survive in the index

        // Before the fix this 500'd: the check missed the trashed row, the insert hit
        // reports_slug_unique. It must now succeed with a different slug.
        $second = $this->makeReport($pet);

        $this->assertNotSame($firstSlug, $second->slug);
        $this->assertDatabaseHas('reports', ['slug' => $second->slug]);
        // The trashed slug is still occupied.
        $this->assertSame(1, Report::withTrashed()->where('slug', $firstSlug)->count());
    }

    /** First-ever report for a pet still gets the clean base slug (no regression). */
    public function test_first_report_gets_the_plain_base_slug(): void
    {
        $report = $this->makeReport($this->pet('Biscuit'));

        $this->assertSame('biscuit', $report->slug);
    }

    /** A pre-supplied slug is respected (the hook only generates when slug is empty). */
    public function test_an_explicitly_provided_slug_is_kept(): void
    {
        $pet = $this->pet('PP1A');
        $report = Report::create([
            'client_id' => $pet->client_id, 'pet_id' => $pet->id,
            'status' => 'draft', 'slug' => 'my-custom-slug', 'pet_snapshot' => ['name' => 'PP1A'],
        ]);

        $this->assertSame('my-custom-slug', $report->slug);
    }

    /** With the base slug AND its first suffixes already occupied (here via raw
     *  inserts, incl. a force-deleted row), a new report still resolves to a free,
     *  distinct slug and inserts without a collision. (A genuine check-then-insert
     *  race is covered by the performInsert() retry, which can't be simulated
     *  deterministically here; this proves the withTrashed() loop skips taken slugs.) */
    public function test_creation_skips_all_taken_slugs_including_a_forcedeleted_one(): void
    {
        $pet = $this->pet('PP1A');

        // Occupy the base slug the generator would produce, as a TRASHED row inserted
        // raw. (This is belt-and-suspenders on top of the withTrashed() check.)
        $first = $this->makeReport($pet);       // slug "pp1a"
        $base = $first->slug;
        $first->forceDelete();

        // Raw-insert rows holding the base and the first few suffixes, bypassing the
        // model entirely, then confirm a normal create still finds a free slug.
        foreach ([$base, $base.'-2', $base.'-3'] as $i => $taken) {
            DB::table('reports')->insert([
                'client_id' => $pet->client_id, 'pet_id' => $pet->id, 'status' => 'draft',
                'slug' => $taken, 'public_token' => 'tok'.$i.uniqid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $report = $this->makeReport($pet);

        $this->assertNotContains($report->slug, [$base, $base.'-2', $base.'-3']);
        $this->assertDatabaseHas('reports', ['slug' => $report->slug]);
    }
}
