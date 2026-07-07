<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Unpublish (published → draft, same URL) + the public publish-gate. A draft /
 * unpublished report must show the branded "being finalised" holding page at its
 * own URL — never its content, never a 404 — and re-publishing serves it again at
 * the unchanged URL. Also confirms a freshly unpublished report's sends re-block.
 */
class UnpublishReportTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'admin'.uniqid().'@e.com', 'password' => Hash::make('secret'),
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Log in a user with an EXPLICIT role (so isAdmin() reflects that role) and
     *  return it — for the admin-preview / non-admin publish-gate cases. */
    private function loginAs(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@e.com',
            'password' => Hash::make('secret'), 'role' => $role,
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function makeReport(string $status = 'published', string $email = 'owner@example.test'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => $email]);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id, 'order_id' => 'ORD-U', 'sample_id' => 'ORD-U',
            'report_date' => '2026-06-17', 'phylum_data' => ['Firmicutes' => 45], 'diversity_score' => 2.4,
            'csv_data' => ['phylum_totals' => []],
        ]);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id,
            'status' => $status, 'pet_snapshot' => ['name' => 'Biscuit'],
        ]);
        $report->steps()->create(['title' => 'S', 'type' => 'prose', 'stage_label' => 'Phase 1', 'body' => 'x', 'position' => 0]);

        return $report;
    }

    // ── Public route publish-gate ────────────────────────────────────────────
    public function test_published_report_serves_its_content(): void
    {
        $report = $this->makeReport('published');

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertSee('Biscuit')
            ->assertSee('Petbiome Microbiome Profile')
            ->assertDontSee('being finalised');
    }

    public function test_draft_report_shows_holding_page_not_content_and_not_404(): void
    {
        $report = $this->makeReport('draft');

        $res = $this->get('/report/'.$report->public_token)
            ->assertOk()                               // NOT a 404
            ->assertSee('This report is being finalised');

        // No report content leaks on the holding page.
        $res->assertDontSee('Petbiome Microbiome Profile');
        $res->assertDontSee('Biscuit');
    }

    public function test_draft_report_pdf_and_subscribe_are_also_gated(): void
    {
        $report = $this->makeReport('draft');

        $this->get('/report/'.$report->public_token.'/pdf')
            ->assertOk()
            ->assertSee('This report is being finalised')
            ->assertHeaderMissing('content-disposition');   // not a PDF download

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee('This report is being finalised');
    }

    public function test_unknown_token_still_404s(): void
    {
        $this->get('/report/notarealtoken')->assertNotFound();
    }

    // ── Admin preview of an unpublished report ───────────────────────────────

    public function test_unpublished_report_shows_admin_preview_to_a_logged_in_admin(): void
    {
        $this->loginAs(User::ROLE_ADMIN);
        $report = $this->makeReport('draft');

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            // Full report content is shown to the admin…
            ->assertSee('Biscuit')
            ->assertSee('Petbiome Microbiome Profile')
            // …with the clear admin-only preview banner…
            ->assertSee('Admin preview')
            // …and NOT the public holding page.
            ->assertDontSee('This report is being finalised');
    }

    public function test_admin_preview_hides_the_download_pdf_option(): void
    {
        // The PDF route is published-only, so the download button is hidden while the
        // report is an unpublished admin preview. (Super admins preview too.)
        $this->loginAs(User::ROLE_SUPER_ADMIN);
        $report = $this->makeReport('draft');

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertSee('Admin preview')
            ->assertDontSee('Download PDF');
    }

    public function test_unpublished_report_shows_holding_page_to_an_anonymous_visitor(): void
    {
        // DRAFT PRIVACY: a valid token with NO session must still get the holding page,
        // never the content and never the admin banner.
        $report = $this->makeReport('draft');

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertSee('This report is being finalised')
            ->assertDontSee('Petbiome Microbiome Profile')
            ->assertDontSee('Biscuit')
            ->assertDontSee('Admin preview');
    }

    public function test_authenticated_non_admin_still_gets_the_holding_page(): void
    {
        // The pivot is the admin ROLE, not merely being logged in — a non-admin
        // session is treated like the public and never sees unpublished content.
        $this->loginAs('viewer');
        $report = $this->makeReport('draft');

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertSee('This report is being finalised')
            ->assertDontSee('Petbiome Microbiome Profile')
            ->assertDontSee('Admin preview');
    }

    public function test_unpublished_pdf_is_gated_even_for_an_admin(): void
    {
        // PDF stays PUBLISHED-ONLY for everyone — no admin exception.
        $this->loginAs(User::ROLE_ADMIN);
        $report = $this->makeReport('draft');

        $this->get('/report/'.$report->public_token.'/pdf')
            ->assertOk()
            ->assertSee('This report is being finalised')
            ->assertHeaderMissing('content-disposition');   // not a PDF download
    }

    public function test_unpublished_subscribe_is_gated_even_for_an_admin(): void
    {
        // Subscribe stays PUBLISHED-ONLY too — no working checkout for a draft.
        $this->loginAs(User::ROLE_ADMIN);
        $report = $this->makeReport('draft');

        $this->get('/report/'.$report->public_token.'/subscribe')
            ->assertOk()
            ->assertSee('This report is being finalised')
            ->assertDontSee('Admin preview');
    }

    public function test_published_report_is_unchanged_for_an_admin_viewer(): void
    {
        // A published report: full content, PDF button present, and NO admin-preview
        // banner (the banner is strictly the unpublished-preview case).
        $this->loginAs(User::ROLE_ADMIN);
        $report = $this->makeReport('published');

        $this->get('/report/'.$report->public_token)
            ->assertOk()
            ->assertSee('Petbiome Microbiome Profile')
            ->assertSee('Download PDF')
            ->assertDontSee('Admin preview')
            ->assertDontSee('being finalised');
    }

    // ── Unpublish action ─────────────────────────────────────────────────────
    public function test_unpublish_reverts_to_draft_keeping_the_same_token(): void
    {
        $this->actingAsAdmin();
        $report = $this->makeReport('published');
        $tokenBefore = $report->public_token;

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionVisible('unpublish')
            ->callAction('unpublish')
            ->assertHasNoActionErrors()
            ->assertNotified('Report unpublished');

        $fresh = $report->fresh();
        $this->assertSame('draft', $fresh->status);
        // URL is unchanged — the token is NOT regenerated.
        $this->assertSame($tokenBefore, $fresh->public_token);
    }

    public function test_unpublish_action_only_shows_when_published(): void
    {
        $this->actingAsAdmin();

        // Draft: Publish visible, Unpublish hidden.
        $draft = $this->makeReport('draft');
        Livewire::test(EditReport::class, ['record' => $draft->getRouteKey()])
            ->assertActionVisible('publish')
            ->assertActionHidden('unpublish');

        // Published: Unpublish visible, Publish hidden.
        $published = $this->makeReport('published');
        Livewire::test(EditReport::class, ['record' => $published->getRouteKey()])
            ->assertActionVisible('unpublish')
            ->assertActionHidden('publish');
    }

    public function test_unpublish_then_republish_round_trips_at_the_same_url(): void
    {
        $this->actingAsAdmin();
        $report = $this->makeReport('published');
        $token = $report->public_token;

        // Published → serves content.
        $this->get('/report/'.$token)->assertOk()->assertSee('Petbiome Microbiome Profile');

        // Unpublish → same URL now shows the holding page.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('unpublish');
        $this->get('/report/'.$token)
            ->assertOk()
            ->assertSee('This report is being finalised')
            ->assertDontSee('Petbiome Microbiome Profile');

        // Re-publish → same URL serves the report again.
        Livewire::test(EditReport::class, ['record' => $report->fresh()->getRouteKey()])
            ->callAction('publish');
        $this->assertSame($token, $report->fresh()->public_token);
        $this->get('/report/'.$token)
            ->assertOk()
            ->assertSee('Petbiome Microbiome Profile')
            ->assertDontSee('being finalised');
    }

    // ── Send-gate interaction ────────────────────────────────────────────────
    public function test_unpublishing_re_blocks_the_send_actions(): void
    {
        $this->actingAsAdmin();
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_X');
        $report = $this->makeReport('published');

        // Published: both send channels are enabled.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertActionEnabled('send_via_klaviyo')
            ->assertActionEnabled('send_via_app');

        // Unpublish, then the publish-gate must disable both again.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('unpublish');

        Livewire::test(EditReport::class, ['record' => $report->fresh()->getRouteKey()])
            ->assertActionDisabled('send_via_klaviyo')
            ->assertActionDisabled('send_via_app');
    }
}
