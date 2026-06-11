<?php

namespace App\Filament\Pages;

use App\Mail\IssueReported;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReportAnIssue extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?string $navigationLabel = 'Report an Issue';

    protected static ?string $title = 'Report an Issue';

    /**
     * Grouped under "System", listed after Settings.
     */
    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.report-an-issue';

    /**
     * Where the issue report is delivered.
     */
    protected const RECIPIENT = 'info@cp.agency';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('subject')
                    ->label('Subject')
                    ->required()
                    ->maxLength(255),
                Select::make('category')
                    ->label('Category')
                    ->options([
                        'Bug' => 'Bug',
                        'Feature request' => 'Feature request',
                        'Question' => 'Question',
                        'Other' => 'Other',
                    ])
                    ->native(false),
                Textarea::make('description')
                    ->label('Description')
                    ->required()
                    ->rows(6),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('submit')
                ->label('Submit')
                ->submit('submit'),
        ];
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $user = auth()->user();

        $mailable = new IssueReported(
            issueSubject: $data['subject'],
            category: $data['category'] ?? null,
            description: $data['description'],
            reporterName: $user?->name ?? 'Unknown user',
            reporterEmail: $user?->email ?? 'unknown@cp.agency',
            reportedAt: now()->toDayDateTimeString(),
        );

        try {
            Mail::to(self::RECIPIENT)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('Failed to send issue report email.', [
                'error' => $e->getMessage(),
                'reporter' => $user?->email,
            ]);

            Notification::make()
                ->title('Could not send your report')
                ->body('Something went wrong while sending your report. Please try again later or contact support directly.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Your issue has been reported')
            ->success()
            ->send();

        $this->form->fill();
    }
}
