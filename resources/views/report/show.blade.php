{{--
    ============================================================================
    THIS REPORT RENDERS IN TWO PLACES:
      - Web view : resources/views/report/show.blade.php   (this file)
      - PDF      : resources/views/report/pdf.blade.php     (DomPDF download)

    They are SEPARATE templates on purpose: DomPDF cannot use this view's
    Tailwind CSS or its Chart.js charts. Styling is duplicated by necessity and
    will not be shared. SHARED CONTENT/DATA lives in app/Support/ReportContent.php
    (microbes, insights, phylum colours, healthy-dog baseline) so it stays in
    sync across both templates.

    >>> IF YOU CHANGE REPORT CONTENT OR LAYOUT, UPDATE BOTH TEMPLATES. <<<
    See docs/report-pdf.md.
    ============================================================================
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $report->petField('name') }} - Petbiome Microbiome Profile</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    {{-- Feedbucket is a STAFF feedback widget — only inject it for an authenticated
         admin previewing the report, never for public/customer report viewers. --}}
    @auth
        @include('partials.feedbucket')
    @endauth
    <link rel="stylesheet" href="{{ asset('css/report.css') }}">
    <style>
        /* Plan ("Recommended Next Steps") product cards — stack the fixed-square
           thumbnail to full-width on narrow screens so the content flows below.
           !important overrides the inline flex/width/height on the thumb. */
        @media (max-width: 600px) {
            .plan-product__thumb {
                flex: 0 0 auto !important;
                width: 100% !important;
                height: 180px !important;
            }
        }

        /* B1 — Subscribe pricing box: below the sm breakpoint, stack both columns
           full-width. !important overrides the inline `flex:0 1 65/35%` +
           `min-width:300/240px` that otherwise force horizontal overflow at ~375px
           and below (the usable width inside the card is only ~295px). */
        @media (max-width: 639px) {
            .sub-panel__info,
            .sub-panel__price {
                flex: 1 1 100% !important;
                min-width: 0 !important;
            }
        }

        /* D2 — "Your plan at a glance": tidy even 2-col grid on mobile, so a lone
           last box no longer stretches full-width (from flex:1). */
        @media (max-width: 600px) {
            .phase-strip {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
            }
            .phase-strip > div {
                min-width: 0 !important;
            }
        }

        /* D1 — diversity slider band labels: shrink so the three labels
           (incl. "Medium (1.9–2.5)") don't collide on narrow screens. */
        @media (max-width: 420px) {
            .slider-band-labels { font-size: 11px !important; }
        }

        /* Gut-wall gauge: its 220px canvas + label row would overflow the card on
           the smallest phones (~320px, card inner ~200px). Cap to the column width
           and let the canvas scale down (it stays 220px on larger screens via the
           max-width). The 220×120 buffer just displays scaled, keeping the ratio. */
        @media (max-width: 380px) {
            .gauge-wrap {
                width: 100% !important;
                max-width: 220px;
                height: auto !important;
            }
            .gauge-wrap canvas { width: 100% !important; height: auto !important; display: block; }
            .gauge-labels { width: 100% !important; max-width: 220px; }
        }
    </style>
    {{-- Flag JS as available before first paint so scroll-reveal can hide-then-reveal
         without flashing. With JS off, this never runs and all content stays visible. --}}
    <script>document.documentElement.classList.add('js');</script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light-grey text-gray-800">

    @php
        $phylumData = $report->phylum_data ?? [];
        $ownerName = !empty($report->petClient?->name) ? $report->petClient->name : 'Owner';
    @endphp

    {{-- ============================================================ --}}
    {{-- 1. HEADER --}}
    {{-- ============================================================ --}}
    <header>
        {{-- Top navy bar --}}
        <div class="bg-navy text-white">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 sm:py-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <img src="/images/biome4pets-logo-white.png" alt="Biome4Pets" style="height:54px; width:auto; display:block;">
                </div>
                <a
                    href="{{ route('report.pdf', $report->public_token) }}"
                    class="inline-flex items-center gap-2 bg-teal hover:bg-teal/90 text-white text-sm font-semibold py-2.5 px-5 rounded-lg shadow-sm hover:shadow-md hover:-translate-y-0.5 transition duration-300 whitespace-nowrap self-start sm:self-auto"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Download PDF
                </a>
            </div>
        </div>

        {{-- Hero banner --}}
        <div class="bg-gradient-to-b from-white/50 to-light-blue">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-12 sm:py-16 text-center">
                {{-- Logo --}}
                <img
                    src="/images/biome4pets-logo.png"
                    alt="Biome4Pets - Microbiome Testing Service"
                    class="mx-auto w-44 sm:w-52 h-auto mb-8 sm:mb-10"
                />

                {{-- Title --}}
                <h2 class="text-4xl sm:text-5xl font-extrabold text-navy tracking-tight mb-5">Petbiome Microbiome Profile</h2>
                <div class="mx-auto h-1 w-16 bg-teal rounded-full mb-8 sm:mb-10"></div>

                {{-- Owner / Pet cover card --}}
                <div class="lift mx-auto max-w-md bg-white/70 backdrop-blur-sm ring-1 ring-white/70 rounded-2xl shadow-[0_18px_48px_-24px_rgba(48,28,71,0.30)] px-4 py-5 sm:px-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-navy/10">
                        <div class="px-6 py-3 sm:py-2 text-center">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-teal mb-1.5">Owner</p>
                            <p class="text-lg sm:text-xl font-bold text-navy leading-tight">{{ $ownerName }}</p>
                        </div>
                        <div class="px-6 py-3 sm:py-2 text-center">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-teal mb-1.5">Pet</p>
                            <p class="text-lg sm:text-xl font-bold text-navy leading-tight">{{ $report->petField('name') ?? '-' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Report date --}}
                <p class="mt-6 text-sm font-medium text-navy/55">{{ \Carbon\Carbon::parse($report->report_date)->format('F j, Y') }}</p>
            </div>
        </div>

        {{-- Contact bar --}}
        <div class="bg-navy/90 text-white text-xs sm:text-sm">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-2 flex flex-col sm:flex-row sm:justify-center gap-1 sm:gap-6 text-center">
                <span>info@biome4pets.com</span>
                <span class="hidden sm:inline">&middot;</span>
                <span>www.biome4pets.com</span>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-10 sm:py-14 space-y-12 sm:space-y-14">

        {{-- ============================================================ --}}
        {{-- 2. VETERINARY SUMMARY (Static) --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Veterinary Summary</h2>
            </div>
            <div class="report-body space-y-6">
                <p class="text-gray-700 leading-relaxed">This is the most important section of your report. Imbalances in the gut microbiome (dysbiosis) can contribute to inflammation, digestive issues, and reduced gut stability. These are often driven by specific bacterial groups that disrupt normal gut function. We have identified key imbalances in your dog's microbiome. Addressing these is the most effective way to restore balance and improve overall health.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-light-blue rounded-2xl p-5 lift">
                        <h3 class="font-bold text-navy mb-2">Priority Guidance</h3>
                        <p class="text-sm text-navy/80">Focus on the recommendations in this section before making other dietary changes. Correcting the primary imbalance may reduce the need for further intervention.</p>
                    </div>
                    <div class="bg-light-blue rounded-2xl p-5 lift">
                        <h3 class="font-bold text-navy mb-2">Summary of Findings</h3>
                        <p class="text-sm text-navy/80">On the next page is a summary of the dysbiosis identified, along with targeted recommendations to help restore balance.</p>
                        <p class="text-sm text-navy/80 mt-2">Support: For questions or further guidance, contact info@biome4pets.com</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================================================ --}}
        {{-- YOUR DOG'S PERSONAL SUMMARY --}}
        {{-- ============================================================ --}}
        @php
            // Pet name reused from the hero accessor, with a readable fallback.
            $petName = $report->petField('name') ?: 'your dog';
            // Boilerplate, same for every report. {pet}/{Pet} are swapped for
            // the pet's (proper-noun) name — both tokens map to the same value.
            $signsOfStability = trim((string) \App\Models\Setting::get(\App\Models\Setting::SIGNS_OF_STABILITY, ''));
            $signsOfStability = $signsOfStability !== ''
                ? str_replace(['{pet}', '{Pet}'], $petName, $signsOfStability)
                : '';
        @endphp
        @if(filled($report->ai_summary) || !empty($report->vet_summary) || !empty($report->recommended_actions) || filled($report->goal) || filled($report->vet_notes) || $signsOfStability !== '')
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Your Dog's Personal Summary</h2>
            </div>
            <div class="report-body space-y-4">
                @if(filled($report->ai_summary))
                    <p class="text-gray-700 leading-relaxed">{{ $report->ai_summary }}</p>
                @endif

                @if(!empty($report->vet_summary))
                    <p class="text-gray-700 leading-relaxed">{{ $report->vet_summary }}</p>
                @endif

                @if(filled($report->goal))
                    <h3 class="font-bold text-navy text-base">Goal</h3>
                    <div class="border-l-4 border-teal pl-4">
                        <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line">{{ $report->goal }}</p>
                    </div>
                @endif

                @if(!empty($report->recommended_actions))
                    <h3 class="font-bold text-navy text-base">Recommended Actions</h3>
                    <div class="border-l-4 border-teal pl-4">
                        <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line">{{ $report->recommended_actions }}</p>
                    </div>
                @endif

                @if(filled($report->vet_notes))
                    <h3 class="font-bold text-navy text-base">Additional Notes</h3>
                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ $report->vet_notes }}</p>
                @endif

                @if($signsOfStability !== '')
                    <h3 class="font-bold text-navy text-base">Signs of Stability</h3>
                    <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line">{{ $signsOfStability }}</p>
                @endif
            </div>
        </section>
        @endif

        {{-- ============================================================ --}}
        {{-- UNDERSTANDING YOUR DOG'S RESULTS (Static) --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Understanding Your Dog's Results</h2>
            </div>
            <div class="report-body">
                <p class="text-gray-700 leading-relaxed">This section explains the current state of your dog's microbiome, including how stable, diverse, and resilient it is &mdash; key factors that influence gut health and overall wellbeing.</p>
            </div>
        </section>

        {{-- ============================================================ --}}
        {{-- 3. MICROBIOME OVERVIEW --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Microbiome Overview</h2>
            </div>
            <div class="report-body">
                @php
                    // Band cutoffs + labels come from ONE place (App\Support\ReportContent);
                    // this view only maps the semantic tone to its Tailwind palette.
                    $toneText = ['bad' => 'text-red-600', 'warn' => 'text-amber-600', 'good' => 'text-green-600'];
                    $toneBg = ['bad' => 'bg-red-50 border-red-300', 'warn' => 'bg-amber-50 border-amber-300', 'good' => 'bg-green-50 border-green-300'];

                    $ds = $report->diversity_score ?? 0;
                    $dsBand = \App\Support\ReportContent::diversityBand($ds);
                    $dsLabel = $dsBand['label']; $dsColor = $toneText[$dsBand['tone']]; $dsBg = $toneBg[$dsBand['tone']];

                    $sr = $report->species_richness ?? 0;
                    $srBand = \App\Support\ReportContent::richnessBand($sr);
                    $srLabel = $srBand['label']; $srColor = $toneText[$srBand['tone']]; $srBg = $toneBg[$srBand['tone']];

                    $dyb = $report->dysbiosis_score ?? 0;
                    $dybBand = \App\Support\ReportContent::dysbiosisBand($dyb);
                    $dybLabel = $dybBand['label']; $dybColor = $toneText[$dybBand['tone']]; $dybBg = $toneBg[$dybBand['tone']];
                @endphp

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    {{-- Diversity Score --}}
                    <div class="border-2 rounded-2xl p-6 text-center lift {{ $dsBg }}">
                        <p class="text-sm font-semibold text-gray-500 mb-2">Diversity Score</p>
                        <p class="text-4xl font-bold {{ $dsColor }}">{{ $ds }}</p>
                        <p class="text-sm font-semibold mt-1 {{ $dsColor }}">{{ $dsLabel }}</p>
                        <div class="mt-3 text-xs text-gray-500 space-y-0.5">
                            @foreach(\App\Support\ReportContent::diversityLegend() as $b)
                                <p><span class="{{ $toneText[$b['tone']] }} font-semibold">{{ $b['label'] }}</span> {{ $b['range'] }}</p>
                            @endforeach
                        </div>
                    </div>

                    {{-- Species Richness --}}
                    <div class="border-2 rounded-2xl p-6 text-center lift {{ $srBg }}">
                        <p class="text-sm font-semibold text-gray-500 mb-2">Species Richness</p>
                        <p class="text-4xl font-bold {{ $srColor }}">{{ $sr }}</p>
                        <p class="text-sm font-semibold mt-1 {{ $srColor }}">{{ $srLabel }}</p>
                        <div class="mt-3 text-xs text-gray-500 space-y-0.5">
                            @foreach(\App\Support\ReportContent::richnessLegend() as $b)
                                <p><span class="{{ $toneText[$b['tone']] }} font-semibold">{{ $b['label'] }}</span> {{ $b['range'] }}</p>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dysbiosis Pattern Score --}}
                    <div class="border-2 rounded-2xl p-6 text-center lift {{ $dybBg }}">
                        <p class="text-sm font-semibold text-gray-500 mb-2">Dysbiosis Pattern Score</p>
                        <p class="text-4xl font-bold {{ $dybColor }}">{{ $dyb }}</p>
                        <p class="text-sm font-semibold mt-1 {{ $dybColor }}">{{ $dybLabel }}</p>
                        <div class="mt-3 text-xs text-gray-500 space-y-0.5">
                            @foreach(\App\Support\ReportContent::dysbiosisLegend() as $b)
                                <p><span class="{{ $toneText[$b['tone']] }} font-semibold">{{ $b['label'] }}</span> {{ $b['range'] }}</p>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Diversity Gradient Slider --}}
                <div class="mt-8 border-t border-gray-100 pt-6">
                    <div class="max-w-2xl mx-auto">
                        <div class="text-center mb-5">
                            <p class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Diversity Score</p>
                            <p class="text-5xl font-extrabold {{ $dsColor }} mt-1">{{ $ds }}</p>
                            <p class="text-base font-bold {{ $dsColor }} mt-1">{{ $dsLabel }}</p>
                        </div>
                        @php
                            // Map score 0–4 to 0–100%
                            $dsPercent = min(max(($ds / 4) * 100, 0), 100);
                        @endphp
                        <div class="relative h-8 rounded-full overflow-hidden shadow-inner" style="background: linear-gradient(to right, #ef4444 0%, #ef4444 47.5%, #f59e0b 47.5%, #f59e0b 62.5%, #22c55e 62.5%, #22c55e 100%);">
                            {{-- Needle marker --}}
                            <div class="absolute top-0 h-full" style="left: {{ $dsPercent }}%;">
                                <div class="relative -translate-x-1/2 h-full flex items-center justify-center" style="width: 6px;">
                                    <div class="w-1.5 bg-navy rounded-full shadow-lg" style="height: calc(100% + 8px);"></div>
                                </div>
                            </div>
                            <div class="absolute -top-8" style="left: {{ $dsPercent }}%;">
                                <div class="relative -translate-x-1/2 bg-navy text-white text-sm font-bold px-2.5 py-1 rounded shadow">{{ $ds }}</div>
                            </div>
                        </div>
                        {{-- Band labels --}}
                        @php $dLow = \App\Support\ReportContent::num(\App\Support\ReportContent::DIVERSITY_LOW_MAX); $dHigh = \App\Support\ReportContent::num(\App\Support\ReportContent::DIVERSITY_HIGH_MIN); @endphp
                        <div class="slider-band-labels flex justify-between mt-2.5 text-sm text-gray-500">
                            <span class="text-red-600 font-bold">Low (&lt;{{ $dLow }})</span>
                            <span class="text-amber-600 font-bold">Medium ({{ $dLow }}–{{ $dHigh }})</span>
                            <span class="text-green-600 font-bold">High (&gt;{{ $dHigh }})</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================================================ --}}
        {{-- 4. YOUR DOG VS HEALTHY MICROBIOME --}}
        {{-- ============================================================ --}}
        @if(count($phylumData) > 0)
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Your Dog vs Healthy Microbiome</h2>
            </div>
            <div class="report-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 sm:gap-8">
                    <div class="text-center chart-frame lift">
                        <h3 class="font-bold text-navy mb-4">Healthy Dog</h3>
                        <div class="relative mx-auto" style="max-width: 300px;">
                            <canvas id="healthyPieChart"></canvas>
                        </div>
                    </div>
                    <div class="text-center chart-frame lift">
                        <h3 class="font-bold text-navy mb-4">Your Dog</h3>
                        <div class="relative mx-auto" style="max-width: 300px;">
                            <canvas id="yourDogPieChart"></canvas>
                        </div>
                    </div>
                </div>

                {{-- Phylum Distribution Donut --}}
                <div class="mt-8 border-t border-gray-100 pt-8">
                    <h3 class="font-bold text-navy mb-4 text-center">Phylum Distribution</h3>
                    <div class="chart-frame flex flex-col md:flex-row items-center justify-center gap-8">
                        {{-- Fluid square: caps at 280px but shrinks to fit the column
                             on narrow screens (no fixed 280px overflow). Chart.js keeps
                             it square via maintainAspectRatio + the 1/1 aspect-ratio. --}}
                        <div class="relative mx-auto" style="width: 100%; max-width: 280px; aspect-ratio: 1 / 1;">
                            <canvas id="phylumDonutChart"></canvas>
                        </div>
                        <div id="phylumDonutLegend" class="space-y-2 text-sm"></div>
                    </div>
                </div>
            </div>
        </section>
        @endif

        {{-- ============================================================ --}}
        {{-- 5. MICROBIOME CLASSIFICATION --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Microbiome Classification</h2>
            </div>
            <div class="report-body space-y-6">
                @php
                    $classification = $report->microbiome_classification ?? 'Unknown';
                    $classCards = [
                        [
                            'name' => 'Stable',
                            'color' => 'green',
                            'border' => 'border-green-400',
                            'bg' => 'bg-green-50',
                            'text' => 'text-green-700',
                            'glow' => 'ring-4 ring-green-300 shadow-lg shadow-green-200',
                            'desc' => 'Core bacteria present at healthy levels',
                        ],
                        [
                            'name' => 'Imbalanced',
                            'color' => 'amber',
                            'border' => 'border-amber-400',
                            'bg' => 'bg-amber-50',
                            'text' => 'text-amber-700',
                            'glow' => 'ring-4 ring-amber-300 shadow-lg shadow-amber-200',
                            'desc' => 'Core bacteria present but at levels that may impact gut health',
                        ],
                        [
                            'name' => 'Imbalanced & Depleted',
                            'color' => 'red',
                            'border' => 'border-red-400',
                            'bg' => 'bg-red-50',
                            'text' => 'text-red-700',
                            'glow' => 'ring-4 ring-red-300 shadow-lg shadow-red-200',
                            'desc' => 'Key beneficial bacteria low or missing',
                        ],
                    ];
                @endphp

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach($classCards as $card)
                        @php
                            $isActive = $classification === $card['name'];
                            $classes = "border-2 rounded-2xl p-5 text-center transition-all {$card['border']} {$card['bg']}";
                            if ($isActive) $classes .= " {$card['glow']}";
                            else $classes .= " opacity-50";
                        @endphp
                        <div class="{{ $classes }}">
                            <h3 class="font-bold text-lg {{ $card['text'] }}">{{ $card['name'] }}</h3>
                            <p class="text-sm {{ $card['text'] }}/80 mt-1">{{ $card['desc'] }}</p>
                        </div>
                    @endforeach
                </div>

                @php
                    $activeCard = collect($classCards)->firstWhere('name', $classification);
                @endphp
                @if($activeCard)
                    <div class="border-2 {{ $activeCard['border'] }} {{ $activeCard['bg'] }} rounded-2xl p-5 text-center">
                        <p class="text-sm text-gray-600">Your Dog's Classification:</p>
                        <p class="text-2xl font-bold {{ $activeCard['text'] }} mt-1">{{ $classification }}</p>
                        <p class="text-sm {{ $activeCard['text'] }}/80 mt-1">{{ $activeCard['desc'] }}</p>
                    </div>
                @endif
            </div>
        </section>

        {{-- ============================================================ --}}
        {{-- 6. 5 KEY MICROBES --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">5 Key Microbes</h2>
            </div>
            <div class="report-body space-y-8">
                @php
                    // Shared with the PDF — see app/Support/ReportContent.php.
                    $microbes = \App\Support\ReportContent::keyMicrobes($report);
                @endphp

                @foreach($microbes as $index => $microbe)
                    <div class="border border-gray-200 rounded-2xl p-6 space-y-4 lift">
                        <h3 class="text-lg font-bold text-navy">{{ $microbe['name'] }}</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h4 class="text-sm font-bold text-navy mb-1">Key Functions</h4>
                                <ul class="text-sm text-gray-700 space-y-1 list-disc list-inside">
                                    @foreach($microbe['functions'] as $fn)
                                        <li>{{ $fn }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-navy mb-1">Key Considerations</h4>
                                <ul class="text-sm text-gray-700 space-y-1 list-disc list-inside">
                                    @foreach($microbe['considerations'] as $con)
                                        <li>{{ $con }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>

                        @if($microbe['interpretation'])
                            <p class="text-sm text-gray-700 leading-relaxed">{{ $microbe['interpretation'] }}</p>
                        @endif

                        <div class="chart-frame relative" style="height: 232px;">
                            <canvas id="microbeChart{{ $index }}"></canvas>
                        </div>

                        <div class="bg-light-blue border-l-4 border-teal rounded-lg px-4 py-2.5">
                            <p class="text-xs text-navy italic">See Veterinary Summary for clinical interpretation and recommendations.</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ============================================================ --}}
        {{-- DIRECT LINKS BETWEEN MICROBES AND HEALTH/DISEASE (Static) --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Direct Links Between Microbes and Health/Disease</h2>
            </div>
            <div class="report-body">
                <p class="text-gray-700 leading-relaxed">The following microbial patterns have been associated with specific health outcomes in dogs. These links provide insight into potential risks and underlying imbalances within your dog's gut microbiome.</p>
            </div>
        </section>

        {{-- ============================================================ --}}
        {{-- 7. HEALTH INSIGHTS --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Microbiome-Driven Health Insights</h2>
            </div>
            <div class="report-body">
                @php
                    // Shared with the PDF — see app/Support/ReportContent.php.
                    $insights = \App\Support\ReportContent::insights($report);
                @endphp

                {{-- Gut Wall Integrity Gauge --}}
                @php
                    $gwScore = $report->score_gut_wall ?? 'N/A';
                    // Map text score to a numeric value for needle position (0-180 degrees)
                    if ($gwScore === 'Low') { $gwDeg = 30; $gwLabel = 'Low'; }
                    elseif ($gwScore === 'Medium') { $gwDeg = 90; $gwLabel = 'Target'; }
                    elseif ($gwScore === 'High') { $gwDeg = 140; $gwLabel = 'High'; }
                    elseif ($gwScore === 'Very High') { $gwDeg = 165; $gwLabel = 'High'; }
                    else { $gwDeg = 90; $gwLabel = 'N/A'; }
                @endphp
                <div class="border border-gray-200 rounded-2xl p-5 mb-6 lift">
                    <h3 class="font-bold text-navy text-center mb-2">Gut Wall Integrity</h3>
                    <p class="text-sm text-gray-500 text-center mb-4">Measures the strength and resilience of the intestinal lining based on key bacterial markers.</p>
                    <div class="flex flex-col items-center">
                        <div class="gauge-wrap" style="width: 220px; height: 120px;">
                            <canvas id="gutWallGauge" width="220" height="120"></canvas>
                        </div>
                        <p class="text-2xl font-bold text-navy mt-3">{{ $gwScore }}</p>
                        <div class="gauge-labels flex justify-between w-[220px] mt-2 text-xs font-semibold">
                            <span class="text-green-600">Low</span>
                            <span class="text-amber-600">Target</span>
                            <span class="text-red-600">High</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($insights as $insight)
                        @php
                            $score = $insight['score'] ?? 'N/A';
                            if ($score === 'Very High') { $scoreColor = 'text-white'; $scoreBg = 'bg-red-800'; }
                            elseif ($score === 'High') { $scoreColor = 'text-white'; $scoreBg = 'bg-red-500'; }
                            elseif ($score === 'Medium') { $scoreColor = 'text-white'; $scoreBg = 'bg-amber-500'; }
                            elseif ($score === 'Low') { $scoreColor = 'text-white'; $scoreBg = 'bg-green-500'; }
                            else { $scoreColor = 'text-gray-500'; $scoreBg = 'bg-gray-100'; }
                        @endphp
                        <div class="border border-gray-200 rounded-2xl p-5 min-h-32 flex items-start gap-4 lift">
                            <div class="flex-1">
                                <h3 class="font-bold text-navy">{{ $insight['title'] }}</h3>
                                <p class="text-sm text-gray-500 mt-1">{{ $insight['desc'] }}</p>
                            </div>
                            <div class="shrink-0">
                                <span class="inline-block px-3 py-1 rounded-full text-sm font-bold {{ $scoreColor }} {{ $scoreBg }}">
                                    {{ $score }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================================================ --}}
        {{-- 8. RECOMMENDED NEXT STEPS (phased plan) --}}
        {{-- ============================================================ --}}
        @if($report->plan_id && $report->steps->isNotEmpty())
        @php
            // Existing accessor reused from the hero card; fallback keeps the
            // templated sentences readable when no pet name is set.
            $petName = $report->petField('name') ?: 'your dog';

            // Global master switch — blank falls back to ON (sensible default).
            $subsGlobalRaw = \App\Models\Setting::get(\App\Models\Setting::SUBSCRIPTIONS_ENABLED);
            $subsGloballyEnabled = blank($subsGlobalRaw) ? true : filter_var($subsGlobalRaw, FILTER_VALIDATE_BOOLEAN);

            // Frozen subscribe-panel data (captured at "Apply plan" time).
            // The global switch can force every plan's subscribe blocks off.
            $snapshot = $report->subscription_snapshot ?? [];
            $subAvailable = $subsGloballyEnabled && (bool) data_get($snapshot, 'available', false);
            $subPrice = data_get($snapshot, 'price');
            // Full (pre-subscription) price, shown struck through to convey the saving.
            $subFullPrice = data_get($snapshot, 'full_price');
            $subBilling = data_get($snapshot, 'billing_note');
            $subUrl = data_get($snapshot, 'url');
            $subIncludes = data_get($snapshot, 'includes', []);
            $planName = $report->plan?->name;

            // Subscribe now goes through the interstitial, which redirects to the
            // LIVE plan's checkout URL (not the frozen snapshot) — so old reports
            // use the current Loop link. CTA shows only when the live plan is
            // enabled and has a URL; otherwise "coming soon".
            $subscribeReady = $report->plan && $report->plan->enabled && filled($report->plan->subscription_url);
            // UTM-tagged link INTO the subscribe interstitial (the final Loop
            // checkout URL is left clean — see subscribe.blade.php). Token is in the
            // path, UTMs in the query, so route resolution is unaffected.
            $subscribeHref = $subscribeReady
                ? \App\Support\Utm::report(route('report.subscribe', ['token' => $report->public_token]), 'subscribe', 'subscribe_cta')
                : null;

            // Saving message now lives in the billing note. Show a badge ONLY
            // when an explicit saving_label was snapshotted — no computed "Save £X".
            $savingLine = filled(data_get($snapshot, 'saving_label')) ? data_get($snapshot, 'saving_label') : null;
        @endphp
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Recommended Next Steps</h2>
            </div>
            <div class="report-body space-y-8">

                {{-- Subscribe panel — only when the plan's subscription is available --}}
                @if($subAvailable)
                <div style="border:1px solid #E3F0FF; border-radius:18px; overflow:hidden; background:#FAF8FF; display:flex; flex-wrap:wrap; box-shadow:0 8px 24px rgba(48,28,71,.06);">
                    <div class="sub-panel__info" style="flex:0 1 65%; min-width:300px; padding:26px 28px;">
                        <span style="display:inline-block; background:#4654A4; color:#fff; font-size:11px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; padding:4px 11px; border-radius:999px;">Recommended</span>
                        <h3 class="text-navy" style="font-size:22px; font-weight:700; margin:13px 0 8px; line-height:1.2;">Subscribe to the {{ $planName ?: 'plan' }}{{ $planName ? ' plan' : '' }}</h3>
                        <p class="text-sm" style="color:#55505A; margin:0 0 16px;">One subscription runs the whole protocol for {{ $petName }}, and the products change automatically as each phase begins.</p>
                        @if(!empty($subIncludes))
                            <ul style="list-style:none; margin:0; padding:0;">
                                @foreach($subIncludes as $inc)
                                    <li style="font-size:14px; padding:5px 0 5px 24px; position:relative;">
                                        <span style="position:absolute; left:0; top:9px; width:12px; height:12px; border-radius:50%; background:#4654A4;"></span>
                                        <b class="text-navy">{{ data_get($inc, 'name') }}</b>@if(! is_null(data_get($inc, 'price'))) - £{{ number_format((float) data_get($inc, 'price'), 2) }}@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <p style="font-size:13px; color:#55505A; margin:14px 0 0;">Pause or cancel anytime.</p>
                    </div>
                    <div class="bg-navy text-white sub-panel__price" style="flex:0 1 35%; min-width:240px; padding:28px 26px; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center;">
                        @if(filled($subFullPrice))
                            <div style="font-size:15px; font-weight:600; color:rgba(255,255,255,.5); text-decoration:line-through; line-height:1;">{{ $subFullPrice }}</div>
                        @endif
                        @if(filled($subPrice))
                            <div style="font-size:28px; font-weight:700; line-height:1; margin-top:{{ filled($subFullPrice) ? '4px' : '0' }};">{{ $subPrice }}</div>
                        @endif
                        @if(filled($subBilling))
                            <div style="font-size:13px; color:rgba(255,255,255,.65); margin:6px 0 14px;">{{ $subBilling }}</div>
                        @endif
                        @if($savingLine)
                            <div style="background:#4654A4; color:#fff; font-size:12px; font-weight:700; padding:3px 12px; border-radius:999px; margin-bottom:14px;">{{ $savingLine }}</div>
                        @endif
                        @if(filled($subscribeHref))
                            <a href="{{ $subscribeHref }}" class="bg-teal hover:bg-teal/90 text-white" style="display:block; width:100%; text-align:center; font-weight:600; font-size:15px; text-decoration:none; padding:13px 18px; border-radius:9px;">Subscribe to plan</a>
                        @else
                            <span aria-disabled="true" style="display:block; width:100%; text-align:center; font-weight:600; font-size:15px; padding:13px 18px; border-radius:9px; background:rgba(255,255,255,.18); color:rgba(255,255,255,.7); cursor:not-allowed;">Subscribe - link coming soon</span>
                        @endif
                        <p style="font-size:12px; color:rgba(255,255,255,.6); margin:12px 0 0; text-align:center;">or buy each product individually below</p>
                    </div>
                </div>
                @endif

                {{-- Your plan at a glance — phase strip from the steps' stage labels --}}
                <div>
                    <h3 style="font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:#55505A; font-weight:600; margin:0 0 14px;">Your plan at a glance</h3>
                    <div class="phase-strip" style="display:flex; gap:8px; flex-wrap:wrap;">
                        @foreach($report->steps as $step)
                            @php
                                $stage = $step->stage_label ?? '';
                                $parts = array_values(array_filter(array_map('trim', preg_split('/·/u', $stage))));
                                $phTag = $parts[0] ?? ('Step ' . $loop->iteration);
                                $phWhen = count($parts) > 1 ? implode(' · ', array_slice($parts, 1)) : '';
                                if ($step->type === 'product') {
                                    $phName = optional($step->products->first()?->catalogProduct)->name ?: $step->title;
                                } else {
                                    $phName = trim(preg_replace('/^Step\s*\d+\s*:\s*/i', '', $step->title));
                                }
                                $phName = preg_replace('/^PetBiome\s+/i', '', $phName);
                                $border = '#4654A4'; $phBg = '#FAF8FF';
                                if (stripos($stage, 'Checkpoint') !== false || stripos($step->title, 'Retest') !== false) {
                                    $border = '#c98a1f'; $phBg = '#fdf8ef';
                                } elseif (stripos($phName, 'Maintenance') !== false) {
                                    $border = '#4a9e3b';
                                } elseif ($loop->first) {
                                    $border = '#4F4065';
                                }
                            @endphp
                            <div style="flex:1; min-width:130px; border-radius:10px; padding:12px 14px; border:1px solid #e3e9ef; border-top:3px solid {{ $border }}; background:{{ $phBg }};">
                                <div style="font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:#55505A;">{{ $phTag }}</div>
                                <div class="text-navy" style="font-weight:600; font-size:14px; margin-top:3px;">{{ $phName }}</div>
                                @if(filled($phWhen))
                                    <div style="font-size:12px; color:#55505A; margin-top:2px;">{{ $phWhen }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Lead: AI-generated plan intro, with the static copy as fallback --}}
                @if(filled($report->plan_intro))
                    <div style="background:#FAF8FF; border:1px solid #E3F0FF; border-radius:12px; padding:18px 20px;">
                        <h3 class="text-navy" style="font-size:16px; font-weight:600; margin:0 0 6px;">Where to focus first</h3>
                        <p class="text-sm" style="color:#55505A; margin:0; line-height:1.6;">{{ $report->plan_intro }}</p>
                    </div>
                @else
                    <div class="text-center max-w-3xl mx-auto">
                        <h3 class="text-xl font-bold text-navy mb-3">Balance the Gut Microbiome</h3>
                        <p class="text-sm text-gray-700 leading-relaxed">Gut dysbiosis can manifest in a variety of physical conditions and symptoms. Restoring balance within the microbiome has the potential to enhance {{ $petName }}'s overall well-being. Below you will find interventions tailored to {{ $petName }}'s microbiome data.</p>
                    </div>
                @endif

                {{-- The full protocol — stepped cards --}}
                <div class="space-y-6">
                    @foreach($report->steps as $step)
                        <div>
                            <div style="background:#FAF8FF; border:1px solid #e3e9ef; border-left:4px solid #4654A4; border-radius:10px; padding:13px 18px;">
                                <div class="text-navy" style="font-weight:600; font-size:17px;">{{ $step->title }}</div>
                                @if(filled($step->stage_label))
                                    <div style="font-size:12px; color:#55505A; margin-top:2px; letter-spacing:.03em;">{{ $step->stage_label }}</div>
                                @endif
                            </div>

                            @if($step->type === 'prose')
                                <div style="padding:14px 4px 0;">
                                    @if(filled($step->body))
                                        <p class="text-sm" style="color:#55505A; margin:0 0 12px; line-height:1.6;">{{ $step->body }}</p>
                                    @endif
                                    @if(filled($step->tip))
                                        <div style="background:#FAF8FF; border:1px solid #E3F0FF; border-radius:10px; padding:14px 16px; font-size:14px; color:#301C47;"><b style="color:#301C47;">Tip:</b> {{ $step->tip }}</div>
                                    @endif
                                </div>
                            @else
                                <div style="display:flex; flex-direction:column; gap:16px; margin-top:16px;">
                                    @foreach($step->products as $product)
                                        @php
                                            $catalog = $product->catalogProduct;
                                            $buyLabel = 'Buy individually' . (! is_null($catalog?->price) ? ' · £' . number_format($catalog->price, 0) : '');

                                            // Optional add-ons with a configured subscription discount show a
                                            // line DERIVED from the catalog product's OWN price + discount.
                                            // No discount configured ⇒ no line (the plain price above still
                                            // shows), so a product can never display a price that isn't its own.
                                            $subDiscounted = $product->inclusion === 'optional' ? $catalog?->discountedPrice() : null;
                                        @endphp
                                        <div class="lift plan-product" style="display:flex; flex-wrap:wrap; gap:20px; padding:20px; border:1px solid #e3e9ef; border-radius:14px; background:#fff; box-shadow:0 1px 2px rgba(48,28,71,.06),0 8px 24px rgba(48,28,71,.06);">
                                            @if($catalog?->image_path)
                                                <div class="plan-product__thumb" style="flex:0 0 140px; width:140px; height:140px; border-radius:10px; border:1px solid #e3e9ef; overflow:hidden; background:#f0f4f8;">
                                                    <img src="{{ $catalog->image_path }}" alt="{{ $catalog->name }}" style="width:100%; height:100%; object-fit:cover; display:block;">
                                                </div>
                                            @else
                                                <div class="plan-product__thumb" style="flex:0 0 140px; width:140px; height:140px; border-radius:10px; background:#E3F0FF; display:flex; align-items:center; justify-content:center;">
                                                    <div class="bg-navy" style="width:56px; height:56px; border-radius:9999px; display:flex; align-items:center; justify-content:center;">
                                                        <span class="text-white" style="font-size:24px; font-weight:700;">{{ strtoupper(substr($catalog?->name ?: '?', 0, 1)) }}</span>
                                                    </div>
                                                </div>
                                            @endif
                                            <div style="flex:1; min-width:0;">
                                                <h4 class="text-navy" style="font-weight:600; font-size:18px; margin:0;">{{ $catalog?->name }}</h4>
                                                @if(! is_null($catalog?->price))
                                                    <div style="font-size:14px; color:#55505A; margin:2px 0 12px; font-weight:500;"><b style="color:#55505A;">£{{ number_format($catalog->price, 2) }}</b></div>
                                                @endif
                                                @if(! is_null($subDiscounted))
                                                    <div style="font-size:13px; color:#55505A; margin:0 0 12px; line-height:1.5;">£{{ \App\Support\ReportContent::num($catalog->price) }}, or £{{ \App\Support\ReportContent::num($subDiscounted) }} with the 6-month subscription discount ({{ $catalog->subscription_discount_percent }}% off)</div>
                                                @endif
                                                @if(filled($product->dose))
                                                    <p class="text-sm" style="margin:5px 0;"><b class="text-navy">Dose:</b> {{ $product->dose }}</p>
                                                @endif
                                                @if(filled($product->duration))
                                                    <p class="text-sm" style="margin:5px 0;"><b class="text-navy">Duration:</b> {{ $product->duration }}</p>
                                                @endif
                                                @if(filled($product->quantity))
                                                    <p class="text-sm" style="margin:5px 0;"><b class="text-navy">Quantity:</b> {{ $product->quantity }}</p>
                                                @endif
                                                @if(filled($product->how_it_helps))
                                                    <p class="text-sm" style="margin:12px 0 0;"><b class="text-navy">How it will help {{ $petName }}:</b> {{ $product->how_it_helps }}</p>
                                                @endif
                                                <div style="display:flex; align-items:center; gap:14px; margin-top:16px; flex-wrap:wrap;">
                                                    {{-- Push the subscription: only OPTIONAL add-ons (not in the plan,
                                                         e.g. the retest kit) get an individual buy button. Included
                                                         products show the tag only. --}}
                                                    @if($product->inclusion === 'optional')
                                                        @if($catalog?->url)
                                                            <a href="{{ $catalog->url }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; background:#fff; color:#38427F; border:1.5px solid #4654A4; font-weight:600; font-size:14px; text-decoration:none; padding:9px 20px; border-radius:8px;">{{ $buyLabel }}</a>
                                                        @endif
                                                        <span style="font-size:12.5px; font-weight:600; color:#a06a14; display:inline-flex; align-items:center; gap:6px;"><span style="width:8px; height:8px; border-radius:50%; background:#d49a2a; display:inline-block;"></span>Optional add-on</span>
                                                    @else
                                                        <span style="font-size:12.5px; font-weight:600; color:#38427F; display:inline-flex; align-items:center; gap:6px;"><span style="width:8px; height:8px; border-radius:50%; background:#4654A4; display:inline-block;"></span>Included in plan</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Closing subscription nudge — only when the plan's subscription
                     is available (same condition as the top subscribe panel). --}}
                @if($subAvailable)
                <div class="bg-navy text-white" style="border-radius:18px; padding:28px 26px; text-align:center;">
                    <h3 style="font-size:18px; font-weight:700; margin:0 0 6px;">Ready to get started?</h3>
                    <p style="font-size:14px; color:rgba(255,255,255,.78); margin:0 auto 18px; max-width:48ch; line-height:1.6;">Subscribe to {{ $planName ?: 'your plan' }} and we'll handle the rest, with the right products, in the right order, delivered to your door.</p>
                    @if(filled($subscribeHref))
                        <a href="{{ $subscribeHref }}" class="bg-teal hover:bg-teal/90 text-white" style="display:inline-block; font-weight:600; font-size:15px; text-decoration:none; padding:13px 32px; border-radius:9px;">Subscribe</a>
                    @else
                        <span aria-disabled="true" style="display:inline-block; font-weight:600; font-size:15px; padding:13px 32px; border-radius:9px; background:rgba(255,255,255,.18); color:rgba(255,255,255,.7); cursor:not-allowed;">Subscribe - link coming soon</span>
                    @endif
                    <p style="font-size:13px; color:rgba(255,255,255,.6); margin:16px 0 0;">Prefer to buy items individually? <a href="{{ \App\Support\Utm::report('https://biome4pets.com/pages/shop', 'shop', 'shop_link') }}" target="_blank" rel="noopener noreferrer" style="color:#816AA1; text-decoration:underline;">Visit our online store</a></p>
                </div>
                @endif

                {{-- Kibble-diet nutritionist CTA. A gentle optimisation nudge (not a
                     warning), shown only when the report's frozen diet is Kibble. --}}
                @if($report->recommendsNutritionist())
                <div style="margin-top:22px; background:#F3F8FC; border:1px solid #D9E6F2; border-left:4px solid #4654A4; border-radius:14px; padding:22px 24px;">
                    <div style="display:flex; align-items:flex-start; gap:14px;">
                        <div style="flex:0 0 auto; width:40px; height:40px; border-radius:9999px; background:#E3F0FF; display:flex; align-items:center; justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4654A4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/></svg>
                        </div>
                        <div>
                            <h3 style="font-size:17px; font-weight:700; color:#301C47; margin:0 0 6px;">We recommend speaking to a nutritionist</h3>
                            <p style="font-size:14px; color:#4b5563; line-height:1.6; margin:0 0 16px; max-width:60ch;">Pets on a kibble diet can benefit from tailored guidance on supporting gut health. Our nutritionists can help you build a plan suited to {{ $petName }}'s individual results.</p>
                            <a href="{{ \App\Support\Utm::report('https://biome4pets.com/nutritionists', 'nutritionist', 'nutritionist_cta') }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; background:#4654A4; color:#fff; font-weight:600; font-size:14px; text-decoration:none; padding:11px 22px; border-radius:9px;">View recommendations &rarr;</a>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </section>
        @endif

        {{-- ============================================================ --}}
        {{-- 8b. MICROBIOME RESTORATION (legacy flat list) --}}
        {{-- Fallback: shown only when the plan layout above is NOT rendered --}}
        {{-- (no plan applied, or no steps), so older reports still render. --}}
        {{-- Remove in a later cleanup slice. --}}
        {{-- ============================================================ --}}
        @if((! $report->plan_id || $report->steps->isEmpty()) && $report->catalogProducts->count() > 0)
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Microbiome Restoration</h2>
            </div>
            <div class="report-body space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-navy mb-2">Product Protocol</h3>
                    <p class="text-sm text-gray-700">Based on your dog's microbiome analysis, a structured and phased approach is recommended.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($report->catalogProducts as $product)
                        <div class="border border-gray-200 rounded-2xl overflow-hidden flex flex-col lift">
                            @if($product->image_path)
                                <img
                                    src="{{ $product->image_path }}"
                                    alt="{{ $product->name }}"
                                    class="w-full h-48 object-cover"
                                >
                            @else
                                <div class="w-full h-48 bg-light-blue flex items-center justify-center">
                                    <div class="w-20 h-20 rounded-full bg-navy flex items-center justify-center">
                                        <span class="text-3xl font-bold text-white">{{ strtoupper(substr($product->name ?: '?', 0, 1)) }}</span>
                                    </div>
                                </div>
                            @endif
                            <div class="p-4 flex flex-col flex-1">
                                <h4 class="font-semibold text-navy mb-1">{{ $product->name }}</h4>
                                @if($product->description)
                                    <p class="text-sm text-gray-600 mb-4 flex-1">{{ $product->description }}</p>
                                @else
                                    <div class="flex-1"></div>
                                @endif
                                @if($product->url)
                                    <a
                                        href="{{ $product->url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-block w-full text-center bg-teal hover:bg-teal/90 text-white text-sm font-semibold py-2.5 px-4 rounded-lg shadow-sm hover:shadow-md hover:-translate-y-0.5 transition duration-300"
                                    >
                                        Shop Now
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        {{-- ============================================================ --}}
        {{-- HELP AND CONTACTS (Static) --}}
        {{-- ============================================================ --}}
        <section data-reveal class="report-card">
            <div class="report-head">
                <h2 class="text-lg font-bold tracking-tight">Help and Contacts</h2>
            </div>
            <div class="report-body space-y-6">
                {{-- Static report-text blocks: admin-editable in Settings → Report Text,
                     resolved via ReportContent so the PDF shows identical copy. --}}
                <div>
                    <h3 class="font-bold text-navy text-base mb-2">About This Report</h3>
                    <p class="text-sm text-gray-700 leading-relaxed">{!! nl2br(e(\App\Support\ReportContent::reportAboutText())) !!}</p>
                </div>

                <div>
                    <h3 class="font-bold text-navy text-base mb-2">Our Approach</h3>
                    <ul class="text-sm text-gray-700 space-y-1 list-disc list-inside">
                        @foreach(\App\Support\ReportContent::reportApproachLines() as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <h3 class="font-bold text-navy text-base mb-2">Support &amp; Next Steps</h3>
                    <p class="text-sm text-gray-700 leading-relaxed">{!! nl2br(e(\App\Support\ReportContent::reportSupportText())) !!}</p>
                </div>
            </div>
        </section>

    </main>

    {{-- ============================================================ --}}
    {{-- 9. FOOTER --}}
    {{-- ============================================================ --}}
    <footer class="bg-navy text-white mt-8 w-full">
        <div class="max-w-5xl mx-auto px-8 py-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <img src="/images/biome4pets-logo-white.png" alt="Biome4Pets" style="height:48px; width:auto; display:block;">
            </div>
            <div class="text-sm text-blue-200 space-y-1 sm:text-right">
                <p>info@biome4pets.com</p>
                <p>www.biome4pets.com</p>
            </div>
        </div>
    </footer>

    {{-- ============================================================ --}}
    {{-- SCROLL REVEAL (minimal, graceful) --}}
    {{-- ============================================================ --}}
    <script>
        (function () {
            var els = document.querySelectorAll('[data-reveal]');
            if (!els.length) return;

            var reveal = function (el) { el.classList.add('is-visible'); };

            var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // No IntersectionObserver support or reduced motion: show everything immediately.
            if (reduceMotion || !('IntersectionObserver' in window)) {
                els.forEach(reveal);
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        reveal(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

            els.forEach(function (el) { observer.observe(el); });
        })();
    </script>

    {{-- ============================================================ --}}
    {{-- CHART.JS SCRIPTS --}}
    {{-- ============================================================ --}}
    <script>
        // Shared with the PDF's SVG pies — see app/Support/ReportContent.php.
        const pieColors = @json(\App\Support\ReportContent::PHYLUM_COLORS);
        const pieColorFallback = @json(\App\Support\ReportContent::PHYLUM_COLOR_FALLBACK);

        function getPieColor(label) {
            return pieColors[label] || pieColorFallback;
        }

        @if(count($phylumData) > 0)
        // Healthy Dog pie chart
        (function() {
            // Shared baseline — see app/Support/ReportContent.php.
            const healthyData = @json(\App\Support\ReportContent::HEALTHY_DOG_PHYLA);
            const labels = Object.keys(healthyData);
            const values = Object.values(healthyData);
            new Chart(document.getElementById('healthyPieChart'), {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: labels.map(l => getPieColor(l)),
                        borderWidth: 2,
                        borderColor: '#ffffff',
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10, font: { size: 11 } } },
                        tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.raw + '%' } }
                    }
                }
            });
        })();

        // Your Dog pie chart - top 6 phyla, rest grouped as "Other"
        (function() {
            const rawData = @json($phylumData);
            const sorted = Object.entries(rawData).sort((a, b) => b[1] - a[1]);
            const top6 = sorted.slice(0, 6);
            const rest = sorted.slice(6);
            const otherTotal = rest.reduce((sum, entry) => sum + entry[1], 0);
            const labels = top6.map(e => e[0]);
            const values = top6.map(e => e[1]);
            if (otherTotal > 0) {
                labels.push('Other');
                values.push(Math.round(otherTotal * 100) / 100);
            }
            new Chart(document.getElementById('yourDogPieChart'), {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: labels.map(l => getPieColor(l)),
                        borderWidth: 2,
                        borderColor: '#ffffff',
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10, font: { size: 11 } } },
                        tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.raw + '%' } }
                    }
                }
            });
        })();

        // Phylum Distribution Donut
        (function() {
            const rawData = @json($phylumData);
            const sorted = Object.entries(rawData).sort((a, b) => b[1] - a[1]);
            const top6 = sorted.slice(0, 6);
            const rest = sorted.slice(6);
            const otherTotal = rest.reduce((sum, entry) => sum + entry[1], 0);
            const labels = top6.map(e => e[0]);
            const values = top6.map(e => e[1]);
            if (otherTotal > 0) {
                labels.push('Other');
                values.push(Math.round(otherTotal * 100) / 100);
            }
            const colors = labels.map(l => getPieColor(l));

            new Chart(document.getElementById('phylumDonutChart'), {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: '#ffffff',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '65%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.raw + '%' } }
                    }
                }
            });

            // Build custom legend
            const legendEl = document.getElementById('phylumDonutLegend');
            if (legendEl) {
                labels.forEach((label, i) => {
                    const row = document.createElement('div');
                    row.className = 'flex items-center gap-2';
                    row.innerHTML = '<span style="width:12px;height:12px;border-radius:50%;background:' + colors[i] + ';display:inline-block;flex-shrink:0;"></span>'
                        + '<span class="text-gray-700">' + label + '</span>'
                        + '<span class="font-semibold text-navy ml-auto">' + values[i] + '%</span>';
                    legendEl.appendChild(row);
                });
            }
        })();
        @endif

        // 5 Key Microbes bar charts
        @php
            $microbeConfigsData = collect($microbes)->values()->map(function($m, $i) {
                return [
                    'index' => $i,
                    'name' => $m['name'],
                    'target' => $m['target'],
                    'high' => $m['high'],
                    'low' => $m['low'],
                    'value' => $m['value'],
                ];
            })->toArray();
        @endphp
        const microbeConfigs = @json($microbeConfigsData);

        microbeConfigs.forEach(function(cfg) {
            const canvas = document.getElementById('microbeChart' + cfg.index);
            if (!canvas) return;

            const allValues = [cfg.target, cfg.high, cfg.low, cfg.value];
            const suggestedMax = Math.ceil(Math.max(...allValues) * 1.2);

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: ['Target', 'High', 'Low', 'Your Pet'],
                    datasets: [{
                        data: allValues,
                        // Brand palette, shade = level (darker = higher, lighter = lower),
                        // matching the PDF bars: Target / High / Low / Your Pet.
                        backgroundColor: ['#2D8BBA', '#31356E', '#6CE5E8', '#4168D5'],
                        borderRadius: 4,
                        // Auto-fit to the available width, capped at 40px — so the
                        // four bars shrink rather than crowd on narrow phones.
                        maxBarThickness: 40,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => ctx.raw + '%' } }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            suggestedMax: suggestedMax,
                            ticks: { callback: val => val + '%' },
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        x: {
                            grid: { display: false },
                            // Keep the four short labels horizontal + readable, and
                            // never drop one, even on a narrow phone canvas.
                            ticks: { font: { size: 11 }, maxRotation: 0, minRotation: 0, autoSkip: false }
                        }
                    }
                }
            });
        });

        // Gut Wall Integrity Semicircular Gauge
        (function() {
            const canvas = document.getElementById('gutWallGauge');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const W = canvas.width;
            const H = canvas.height;
            const cx = W / 2;
            const cy = H - 10;
            const radius = 90;
            const lineWidth = 18;

            // Draw arcs: Low (green) | Target (amber) | High (red)
            const bands = [
                { start: Math.PI, end: Math.PI + (Math.PI / 3), color: '#22c55e' },
                { start: Math.PI + (Math.PI / 3), end: Math.PI + (2 * Math.PI / 3), color: '#f59e0b' },
                { start: Math.PI + (2 * Math.PI / 3), end: 2 * Math.PI, color: '#ef4444' },
            ];

            bands.forEach(function(band) {
                ctx.beginPath();
                ctx.arc(cx, cy, radius, band.start, band.end);
                ctx.lineWidth = lineWidth;
                ctx.strokeStyle = band.color;
                ctx.lineCap = 'butt';
                ctx.stroke();
            });

            // Needle — gwDeg from PHP (0=left, 180=right)
            const needleDeg = {{ $gwDeg }};
            const needleRad = Math.PI + (needleDeg / 180) * Math.PI;
            const needleLen = radius - 8;

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(
                cx + Math.cos(needleRad) * needleLen,
                cy + Math.sin(needleRad) * needleLen
            );
            ctx.lineWidth = 3;
            ctx.strokeStyle = '#301C47';
            ctx.lineCap = 'round';
            ctx.stroke();

            // Center dot
            ctx.beginPath();
            ctx.arc(cx, cy, 6, 0, 2 * Math.PI);
            ctx.fillStyle = '#301C47';
            ctx.fill();
        })();
    </script>

</body>
</html>
