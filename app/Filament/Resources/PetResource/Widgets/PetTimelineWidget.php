<?php

namespace App\Filament\Resources\PetResource\Widgets;

use App\Models\Pet;
use App\Support\PetTimeline;
use Illuminate\Support\Collection;
use Filament\Widgets\Widget;

/**
 * The Pet hub's history timeline (Step 4). A read-only footer widget on the Pet
 * edit page: Filament injects the page record into $record. Type + date-range
 * filters are live; the merge/filter lives in PetTimeline.
 */
class PetTimelineWidget extends Widget
{
    protected static string $view = 'filament.resources.pet-resource.widgets.pet-timeline-widget';

    protected int|string|array $columnSpan = 'full';

    // Auto-injected by Filament on the resource Edit page.
    public ?Pet $record = null;

    // Filters: type ('' = all), and inclusive date range (Y-m-d).
    public string $typeFilter = '';

    public ?string $fromDate = null;

    public ?string $toDate = null;

    public function getEventsProperty(): Collection
    {
        if (! $this->record) {
            return collect();
        }

        return PetTimeline::build(
            $this->record,
            $this->typeFilter !== '' ? $this->typeFilter : null,
            $this->fromDate ?: null,
            $this->toDate ?: null,
        );
    }

    /** @return array<string,string> '' => All, then the PetTimeline types. */
    public function getTypeOptions(): array
    {
        return ['' => 'All'] + PetTimeline::TYPES;
    }
}
