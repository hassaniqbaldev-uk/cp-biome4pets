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
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression guard for the production 500: an EditReport action closure calling a
 * form-refresh method that wasn't callable from its scope ("fillForm does not
 * exist"). These run the real action closures end-to-end so any such "method does
 * not exist" error in an action path fails the suite instead of reaching prod.
 */
class EditReportActionMethodsTest extends TestCase
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
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a@e.com', 'password' => bcrypt('x')]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function makeReport(string $status = 'published'): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner@example.test']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(),
            'report_date' => '2026-06-15',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => $status,
        ]);
    }

    public function test_klaviyo_send_action_runs_without_a_method_error(): void
    {
        Setting::set(Setting::KLAVIYO_ENABLED, '1');
        Setting::setEncrypted(Setting::KLAVIYO_API_KEY, 'pk_test_X');
        Http::fake(['*/api/events/' => Http::response([], 202)]);
        $report = $this->makeReport();

        // Runs the action closure incl. $this->fillForm() — would 500 if that method
        // were not callable. assertNotified proves the closure ran to the end.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_klaviyo')
            ->assertNotified('Report sent to Klaviyo')
            ->assertHasNoActionErrors();
    }

    public function test_app_send_action_runs_without_a_method_error(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $report = $this->makeReport();

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('send_via_app')
            ->assertNotified('Report sent')
            ->assertHasNoActionErrors();
    }

    public function test_header_publish_action_runs_without_a_method_error(): void
    {
        $report = $this->makeReport('draft');

        // The header "Publish Report" action calls $this->fillForm() (internal,
        // protected → fine from the page). Exercising it guards that path too.
        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->callAction('publish')
            ->assertHasNoActionErrors();

        $this->assertSame('published', $report->fresh()->status);
    }

    public function test_done_publish_form_action_refreshes_form_without_method_error(): void
    {
        // THE production line (ReportResource:172-180): a form-embedded action whose
        // closure calls $livewire->refreshFormData(['status']) — this is the exact spot
        // that 500'd with "fillForm does not exist" when it called the protected
        // fillForm() from this (external ReportResource) scope. Mount with ?created=1 so
        // the "Report created" section + done_publish action are present, then execute
        // the REAL action closure end-to-end. A non-callable form-refresh method here
        // would throw "method does not exist" and fail the test instead of reaching prod.
        //
        // (The action lives inside a Forms\Components\Actions block whose ActionContainer
        // key is the dotted statePath `data.done_publishAction`; Livewire's
        // callFormComponentAction() splits on that dot and can't reach it, so we resolve
        // the Action off the live form and ->call() it — same closure, same injected
        // $record + $livewire, run for real.)
        $report = $this->makeReport('draft');

        $component = Livewire::withQueryParams(['created' => 1])
            ->test(EditReport::class, ['record' => $report->getRouteKey()])
            ->assertSet('justCreated', true);

        $action = $component->instance()
            ->getCachedForms()['form']
            ->getComponent('data.done_publishAction')
            ->getAction('done_publish');

        $action->call();

        $this->assertSame('published', $report->fresh()->status);
    }
}
