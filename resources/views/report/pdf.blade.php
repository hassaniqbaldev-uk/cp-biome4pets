{{--
    ============================================================================
    THIS REPORT RENDERS IN TWO PLACES:
      - Web view : resources/views/report/show.blade.php   (Tailwind + Chart.js)
      - PDF      : resources/views/report/pdf.blade.php     (this file, DomPDF)

    They are SEPARATE templates on purpose. DomPDF CANNOT use the web's Tailwind
    CSS, and CANNOT run JavaScript (so the web's Chart.js charts do not exist
    here). This template therefore uses DomPDF-safe markup only:
      - layout via <table> / inline-block, NEVER flexbox or grid
      - charts as server-side SVG delivered through <img src="data:image/svg+xml">
        (DomPDF renders SVG ONLY via an <img>; an inline <svg> shows BLANK)
      - simple CSS, no remote images during render

    SHARED CONTENT/DATA lives in app/Support/ReportContent.php (microbes,
    insights, phylum colours, healthy-dog baseline) so it stays in sync with the
    web view. Styling is duplicated by necessity and is NOT shared.

    >>> IF YOU CHANGE REPORT CONTENT OR LAYOUT, UPDATE BOTH TEMPLATES. <<<
    See docs/report-pdf.md.
    ============================================================================
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        /* Top margin on every page; bottom margin reserves space for the fixed
           footer so flowing content never renders under it.
           NOTE: a universal `* { margin: 0 }` reset NULLIFIES @page margins in
           DomPDF (the reset wins over the page box). Reset margins on a concrete
           element list instead, and keep box-sizing on the universal selector. */
        @page {
            margin: 20mm 11mm 20mm 11mm;
        }
        * {
            box-sizing: border-box;
        }
        body, div, table, tr, td, th, p, h1, h2, h3, h4, h5, h6, ul, ol, li, span, img {
            margin: 0;
            padding: 0;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #55505A;
            line-height: 1.6;
        }
        table {
            border-collapse: collapse;
        }
        p {
            margin-bottom: 8px;
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #55505A;
        }
        h3 {
            font-size: 14px;
            color: #301C47;
            margin-bottom: 6px;
            font-family: Arial, sans-serif;
        }
        li {
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin-bottom: 3px;
            color: #55505A;
        }
        /* Reusable section chrome */
        .section { max-width: 750px; margin: 0 auto 20px auto; page-break-inside: avoid; }
        .section-bar { background-color: #301C47; color: #ffffff; padding: 14px 20px; font-size: 20px; font-weight: bold; }
        .section-body { background-color: #ffffff; border: 1px solid #e6e6ea; border-top: none; padding: 20px; }
        .accent-bar { border-left: 4px solid #4654A4; padding-left: 14px; margin-top: 6px; }
    </style>
</head>
<body>

@php
    use App\Models\Setting;
    use App\Support\ReportContent;
    use App\Support\ChartSvg;

    $phylumData = $report->phylum_data ?? [];
    $petName = $report->petField('name') ?: 'your dog';
    $ownerName = !empty($report->petClient?->name) ? $report->petClient->name : 'Owner';
    $reportDate = \Carbon\Carbon::parse($report->report_date)->format('F j, Y');

    // Shared data — see app/Support/ReportContent.php (kept in sync with the web view).
    $healthyDog = ReportContent::HEALTHY_DOG_PHYLA;
    $top6 = ReportContent::topPhyla($phylumData);
    $microbes = ReportContent::keyMicrobes($report);
    $insights = ReportContent::insights($report);

    // Diversity / Species Richness / Dysbiosis: label + colour by threshold.
    // Band cutoffs + labels come from ONE place (ReportContent — identical to the
    // web view by construction); this view only maps the tone to its hex palette.
    $toneHex = ['bad' => '#dc2626', 'warn' => '#d97706', 'good' => '#16a34a'];

    $ds = $report->diversity_score ?? 0;
    $dsBand = ReportContent::diversityBand($ds);
    $dsLabel = $dsBand['label']; $dsColor = $toneHex[$dsBand['tone']];
    $dsPercent = min(max(($ds / 4) * 100, 0), 100);

    $sr = $report->species_richness ?? 0;
    $srBand = ReportContent::richnessBand($sr);
    $srLabel = $srBand['label']; $srColor = $toneHex[$srBand['tone']];

    $dyb = $report->dysbiosis_score ?? 0;
    $dybBand = ReportContent::dysbiosisBand($dyb);
    $dybLabel = $dybBand['label']; $dybColor = $toneHex[$dybBand['tone']];

    $classification = $report->microbiome_classification ?? 'Unknown';

    // Boilerplate "signs of stability" (mirrors the web view), pet-name templated.
    $signsOfStability = trim((string) Setting::get(Setting::SIGNS_OF_STABILITY, ''));
    $signsOfStability = $signsOfStability !== ''
        ? str_replace(['{pet}', '{Pet}'], $petName, $signsOfStability)
        : '';

    // Gut Wall gauge needle: map the text score to a degree on the 0-180 arc
    // (same mapping as the web canvas gauge).
    $gwScore = $report->score_gut_wall ?? 'N/A';
    if ($gwScore === 'Low') { $gwDeg = 30; }
    elseif ($gwScore === 'Medium') { $gwDeg = 90; }
    elseif ($gwScore === 'High') { $gwDeg = 140; }
    elseif ($gwScore === 'Very High') { $gwDeg = 165; }
    else { $gwDeg = 90; }

    $logoPath = public_path('images/biome4pets-logo.png');
    // DomPDF-safe charts live in App\Support\ChartSvg (server-side SVG emitted as
    // <img> data-URIs — DomPDF renders SVG ONLY via <img>, never inline).
@endphp

{{-- Fixed footer bar on every page. DomPDF positions fixed elements relative to
     the content box (inside the @page margins), so to sit FLUSH at the physical
     page bottom and span FULL width we push out past the margins with negative
     offsets: bottom:-20mm = the 20mm bottom margin (→ page edge), left/right:-11mm
     = the 11mm side margins (→ edge to edge). The 14mm bar fits inside the 20mm
     bottom margin (~6mm clearance), so body content never collides. Left group
     (logo + Biome4Pets Ltd) and right group (email + URL) via a 2-cell table,
     vertically centred. --}}
<div style="background-color: #301C47; color: #ffffff; position: fixed; bottom: -20mm; left: -11mm; right: -11mm; height: 14mm;">
    <table width="100%" cellpadding="0" cellspacing="0" style="height: 14mm; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: middle; height: 14mm; padding-left: 24px; white-space: nowrap; font-family: Arial, sans-serif;">
                <img src="{{ public_path('images/biome4pets-logo-white.png') }}" style="height: 30px; vertical-align: middle; margin-right: 10px;" />
                <span style="vertical-align: middle; font-size: 11px; font-weight: bold; color: #ffffff;">Biome4Pets Ltd</span>
            </td>
            <td style="vertical-align: middle; height: 14mm; padding-right: 24px; text-align: right; white-space: nowrap; font-family: Arial, sans-serif; font-size: 11px; color: #ffffff;">
                info@biome4pets.com &nbsp;&nbsp;&bull;&nbsp;&nbsp; www.biome4pets.com
            </td>
        </tr>
    </table>
</div>

{{-- Fixed running header: DARK logo (inner pages are white), top-left, on every
     page. DomPDF positions fixed `top` relative to the content box (inside the
     @page margin), so top:-14mm lifts it into the top margin (~6mm from the page
     edge) — above body content, which starts at the 20mm margin. --}}
<div style="position: fixed; top: -14mm; left: 0;">
    <img src="{{ $logoPath }}" style="height: 34px;" />
</div>
{{-- Cover (page 1) exclusion: position:absolute paints on the FIRST page only, so
     this white band masks the running header on the cover — where the centred logo
     already lives — without affecting pages 2+. top:-20mm aligns it with the page
     edge to cover the header band; higher z-index paints it over the fixed logo. --}}
<div style="position: absolute; top: -20mm; left: 0; right: 0; height: 20mm; background-color: #ffffff; z-index: 5;"></div>

{{-- ================================================================ --}}
{{-- SECTION 1: COVER PAGE --}}
{{-- A dedicated title page: a full-height single-cell table vertically centres
{{-- the cover block so it fills the page instead of crowding the top. The TOC
{{-- below carries page-break-before, so the cover owns page 1 by itself. --}}
{{-- ================================================================ --}}
<table style="width: 100%; height: 245mm;" cellspacing="0" cellpadding="0">
    <tr>
        <td style="vertical-align: middle; text-align: center;">
            <div style="padding-bottom: 34px; font-family: Arial, sans-serif;">
                <span style="font-size: 28px; font-weight: bold; color: #301C47;">Biome4Pets</span><br>
                <span style="font-size: 13px; color: #4654A4;">Microbiome Testing Service</span>
            </div>

            <div style="background-color: #E3F0FF; padding: 52px 30px; text-align: center;">
                <div style="margin-bottom: 22px;"><img src="{{ $logoPath }}" style="width: 185px;" /></div>
                <div style="font-size: 33px; font-weight: bold; color: #301C47; margin-bottom: 22px;">Petbiome Microbiome Profile</div>
                <div style="height: 3px; width: 70px; background-color: #4654A4; margin: 0 auto 22px auto;"></div>
                <div style="font-size: 13px; color: #4654A4; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 10px;">Prepared For</div>
                <div style="font-size: 24px; font-weight: bold; color: #301C47; margin-bottom: 8px;">{{ $ownerName }} &amp; {{ $report->petField('name') }}</div>
                <div style="font-size: 13px; color: #55505A;">{{ $reportDate }}</div>
            </div>

            <div style="padding-top: 34px;">
                <span style="font-size: 12px; color: #55505A;">info@biome4pets.com &nbsp;&nbsp;&bull;&nbsp;&nbsp; www.biome4pets.com</span>
            </div>
        </td>
    </tr>
</table>

{{-- ================================================================ --}}
{{-- SECTION 2: TABLE OF CONTENTS --}}
{{-- ================================================================ --}}
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">Table of Contents</div>
    <div class="section-body">
        @php
            $toc = [
                'Veterinary Summary', 'Your Dog\'s Personal Summary', 'Microbiome Overview',
                'Your Dog vs Healthy Microbiome', 'Microbiome Classification',
                'Key Microbes', 'Microbiome-Driven Health Insights',
                // The plan/subscribe section is omitted from the contents when staff
                // have hidden it, so the TOC never lists a section that isn't rendered.
                ...(! $report->hide_subscribe ? ['Recommended Next Steps'] : []),
                'Help and Contacts',
            ];
        @endphp
        @foreach($toc as $i => $item)
            <div style="padding: 8px 0; {{ $loop->last ? '' : 'border-bottom: 1px dotted #cccccc;' }} font-size: 13px;">
                <span style="color: #4654A4; font-weight: bold;">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                &nbsp;&mdash;&nbsp; {{ $item }}
            </div>
        @endforeach
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 3: VETERINARY SUMMARY --}}
{{-- ================================================================ --}}
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">Veterinary Summary</div>
    <div class="section-body">
        <p>This is the most important section of your report. Imbalances in the gut microbiome (dysbiosis) can contribute to inflammation, digestive issues, and reduced gut stability. These are often driven by specific bacterial groups that disrupt normal gut function. We have identified key imbalances in your dog's microbiome. Addressing these is the most effective way to restore balance and improve overall health.</p>

        <table style="width: 100%; margin-top: 14px;" cellspacing="0" cellpadding="0">
            <tr>
                <td style="width: 48%; vertical-align: top;">
                    <div style="background-color: #E3F0FF; padding: 15px;">
                        <div style="font-size: 13px; font-weight: bold; color: #301C47; margin-bottom: 4px;">Priority Guidance</div>
                        <div style="font-size: 11px; color: #301C47;">Focus on the recommendations in this section before making other dietary changes. Correcting the primary imbalance may reduce the need for further intervention.</div>
                    </div>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; vertical-align: top;">
                    <div style="background-color: #E3F0FF; padding: 15px;">
                        <div style="font-size: 13px; font-weight: bold; color: #301C47; margin-bottom: 4px;">Summary of Findings</div>
                        <div style="font-size: 11px; color: #301C47;">Below is a summary of the dysbiosis identified, along with targeted recommendations to help restore balance.</div>
                        <div style="font-size: 11px; color: #301C47; margin-top: 8px;">Support: For questions or further guidance, contact info@biome4pets.com</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 4: YOUR DOG'S PERSONAL SUMMARY --}}
{{-- ================================================================ --}}
@if(filled($report->ai_summary) || !empty($report->vet_summary) || filled($report->goal) || !empty($report->recommended_actions) || filled($report->vet_notes) || $signsOfStability !== '')
<div class="section">
    <div class="section-bar">Your Dog's Personal Summary</div>
    <div class="section-body">
        @if(filled($report->ai_summary))
            <p>{{ $report->ai_summary }}</p>
        @endif

        @if(!empty($report->vet_summary))
            <p>{{ $report->vet_summary }}</p>
        @endif

        @if(filled($report->goal))
            <h3 style="margin-top: 14px;">Goal</h3>
            <div class="accent-bar">
                <p>{!! nl2br(e($report->goal)) !!}</p>
            </div>
        @endif

        @if(!empty($report->recommended_actions))
            <h3 style="margin-top: 14px;">Recommended Actions</h3>
            <div class="accent-bar">
                <p>{!! nl2br(e($report->recommended_actions)) !!}</p>
            </div>
        @endif

        @if(filled($report->vet_notes))
            <h3 style="margin-top: 14px;">Additional Notes</h3>
            <p>{!! nl2br(e($report->vet_notes)) !!}</p>
        @endif

        @if($signsOfStability !== '')
            <h3 style="margin-top: 14px;">Signs of Stability</h3>
            <p>{!! nl2br(e($signsOfStability)) !!}</p>
        @endif
    </div>
</div>
@endif

{{-- ================================================================ --}}
{{-- SECTION 5: UNDERSTANDING YOUR DOG'S RESULTS --}}
{{-- ================================================================ --}}
<div class="section">
    <div class="section-bar">Understanding Your Dog's Results</div>
    <div class="section-body">
        <p>This section explains the current state of your dog's microbiome, including how stable, diverse, and resilient it is &mdash; key factors that influence gut health and overall wellbeing.</p>
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 6: MICROBIOME OVERVIEW --}}
{{-- ================================================================ --}}
<div class="section">
    <div class="section-bar">Microbiome Overview</div>
    <div class="section-body">
        <table style="width: 100%; table-layout: fixed;" cellspacing="0" cellpadding="0">
            <tr>
                {{-- Diversity Score --}}
                <td style="width: 32%; vertical-align: top;">
                    <div style="background-color: #FAF8FF; border: 2px solid #4654A4; padding: 14px; text-align: center;">
                        <div style="font-size: 11px; color: #55505A; font-weight: bold; margin-bottom: 6px;">Diversity Score</div>
                        <div style="font-size: 34px; font-weight: bold; color: {{ $dsColor }};">{{ $ds }}</div>
                        <div style="font-size: 11px; font-weight: bold; color: {{ $dsColor }}; margin-top: 2px;">{{ $dsLabel }}</div>
                        <div style="font-size: 9px; color: #55505A; margin-top: 8px; line-height: 1.7;">
                            @foreach(ReportContent::diversityLegend() as $b)<span style="color:{{ $toneHex[$b['tone']] }}; font-weight:bold;">{{ $b['label'] }}</span> {{ $b['range'] }}@if(!$loop->last)<br>@endif @endforeach
                        </div>
                    </div>
                </td>
                <td style="width: 2%;"></td>
                {{-- Species Richness --}}
                <td style="width: 32%; vertical-align: top;">
                    <div style="background-color: #FAF8FF; border: 2px solid #4654A4; padding: 14px; text-align: center;">
                        <div style="font-size: 11px; color: #55505A; font-weight: bold; margin-bottom: 6px;">Species Richness</div>
                        <div style="font-size: 34px; font-weight: bold; color: {{ $srColor }};">{{ $sr }}</div>
                        <div style="font-size: 11px; font-weight: bold; color: {{ $srColor }}; margin-top: 2px;">{{ $srLabel }}</div>
                        <div style="font-size: 9px; color: #55505A; margin-top: 8px; line-height: 1.7;">
                            @foreach(ReportContent::richnessLegend() as $b)<span style="color:{{ $toneHex[$b['tone']] }}; font-weight:bold;">{{ $b['label'] }}</span> {{ $b['range'] }}@if(!$loop->last)<br>@endif @endforeach
                        </div>
                    </div>
                </td>
                <td style="width: 2%;"></td>
                {{-- Dysbiosis --}}
                <td style="width: 32%; vertical-align: top;">
                    <div style="background-color: #FAF8FF; border: 2px solid #4654A4; padding: 14px; text-align: center;">
                        <div style="font-size: 11px; color: #55505A; font-weight: bold; margin-bottom: 6px;">Dysbiosis Pattern Score</div>
                        <div style="font-size: 34px; font-weight: bold; color: {{ $dybColor }};">{{ $dyb }}</div>
                        <div style="font-size: 11px; font-weight: bold; color: {{ $dybColor }}; margin-top: 2px;">{{ $dybLabel }}</div>
                        <div style="font-size: 9px; color: #55505A; margin-top: 8px; line-height: 1.7;">
                            @foreach(ReportContent::dysbiosisLegend() as $b)<span style="color:{{ $toneHex[$b['tone']] }}; font-weight:bold;">{{ $b['label'] }}</span> {{ $b['range'] }}@if(!$loop->last)<br>@endif @endforeach
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Diversity gradient slider --}}
        <div style="border-top: 1px solid #eeeeee; margin-top: 18px; padding-top: 16px; text-align: center;">
            <div style="font-size: 11px; color: #55505A; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;">Diversity Score</div>
            <div style="font-size: 30px; font-weight: bold; color: {{ $dsColor }}; margin: 2px 0;">{{ $ds }}</div>
            <div style="font-size: 12px; font-weight: bold; color: {{ $dsColor }}; margin-bottom: 8px;">{{ $dsLabel }}</div>
            <div style="text-align: center;">{!! ChartSvg::slider($dsPercent) !!}</div>
            <table style="width: 520px; margin: 4px auto 0 auto;" cellspacing="0" cellpadding="0">
                @php $dLow = ReportContent::num(ReportContent::DIVERSITY_LOW_MAX); $dHigh = ReportContent::num(ReportContent::DIVERSITY_HIGH_MIN); @endphp
                <tr>
                    <td style="text-align: left; font-size: 10px; color: #dc2626; font-weight: bold;">Low (&lt;{{ $dLow }})</td>
                    <td style="text-align: center; font-size: 10px; color: #d97706; font-weight: bold;">Medium ({{ $dLow }}-{{ $dHigh }})</td>
                    <td style="text-align: right; font-size: 10px; color: #16a34a; font-weight: bold;">High (&gt;{{ $dHigh }})</td>
                </tr>
            </table>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 7: YOUR DOG VS HEALTHY MICROBIOME --}}
{{-- ================================================================ --}}
@if(count($phylumData) > 0)
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">Your Dog vs Healthy Microbiome</div>
    <div class="section-body">
        <table style="width: 100%;" cellspacing="0" cellpadding="0">
            <tr>
                {{-- Healthy Dog pie --}}
                <td style="width: 48%; vertical-align: top; text-align: center;">
                    <div style="font-size: 14px; font-weight: bold; color: #301C47; margin-bottom: 10px;">Healthy Dog</div>
                    <div style="text-align: center;">{!! ChartSvg::pie($healthyDog) !!}</div>
                    @include('report.partials.pdf-phylum-legend', ['rows' => $healthyDog])
                </td>
                <td style="width: 4%;"></td>
                {{-- Your Dog pie --}}
                <td style="width: 48%; vertical-align: top; text-align: center;">
                    <div style="font-size: 14px; font-weight: bold; color: #301C47; margin-bottom: 10px;">Your Dog</div>
                    <div style="text-align: center;">{!! ChartSvg::pie($top6) !!}</div>
                    @include('report.partials.pdf-phylum-legend', ['rows' => $top6])
                </td>
            </tr>
        </table>

        {{-- Phylum Distribution donut --}}
        <div style="border-top: 1px solid #eeeeee; margin-top: 18px; padding-top: 16px;">
            <div style="font-size: 14px; font-weight: bold; color: #301C47; text-align: center; margin-bottom: 10px;">Phylum Distribution</div>
            <table style="width: 100%;" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="width: 45%; text-align: center; vertical-align: middle;">{!! ChartSvg::pie($top6, 0.62) !!}</td>
                    <td style="width: 55%; vertical-align: middle;">
                        @include('report.partials.pdf-phylum-legend', ['rows' => $top6])
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>
@endif

{{-- ================================================================ --}}
{{-- SECTION 8: MICROBIOME CLASSIFICATION --}}
{{-- ================================================================ --}}
<div class="section">
    <div class="section-bar">Microbiome Classification</div>
    <div class="section-body">
        @php
            $classCards = [
                ['name' => 'Stable', 'border' => '#22c55e', 'text' => '#16a34a', 'active' => '#dcfce7', 'desc' => 'Core bacteria present at healthy levels'],
                ['name' => 'Imbalanced', 'border' => '#f59e0b', 'text' => '#d97706', 'active' => '#fef3c7', 'desc' => 'Core bacteria present but at levels that may impact gut health'],
                ['name' => 'Imbalanced & Depleted', 'border' => '#ef4444', 'text' => '#dc2626', 'active' => '#fee2e2', 'desc' => 'Key beneficial bacteria low or missing'],
            ];
        @endphp
        <table style="width: 100%; margin-bottom: 14px;" cellspacing="0" cellpadding="0">
            <tr>
                @foreach($classCards as $card)
                    @php $isActive = $classification === $card['name']; @endphp
                    <td style="width: 32%; vertical-align: top;">
                        <div style="background-color: {{ $isActive ? $card['active'] : '#FAF8FF' }}; border-top: 4px solid {{ $card['border'] }}; border-left: 1px solid #e6e6ea; border-right: 1px solid #e6e6ea; border-bottom: 1px solid #e6e6ea; padding: 12px; text-align: center;">
                            <div style="font-size: 14px; font-weight: bold; color: {{ $card['text'] }};">{{ $card['name'] }}</div>
                            <div style="font-size: 10px; color: #55505A; margin-top: 4px;">{{ $card['desc'] }}</div>
                        </div>
                    </td>
                    @if(!$loop->last)<td style="width: 2%;"></td>@endif
                @endforeach
            </tr>
        </table>

        @php
            $activeCard = collect($classCards)->firstWhere('name', $classification)
                ?? ['active' => '#FAF8FF', 'border' => '#cccccc', 'text' => '#55505A', 'desc' => ''];
        @endphp
        <div style="background-color: {{ $activeCard['active'] }}; border: 2px solid {{ $activeCard['border'] }}; padding: 14px; text-align: center;">
            <div style="font-size: 11px; color: #55505A;">Your Dog's Classification:</div>
            <div style="font-size: 22px; font-weight: bold; color: {{ $activeCard['text'] }}; margin-top: 4px;">{{ $classification }}</div>
            @if(!empty($activeCard['desc']))
                <div style="font-size: 11px; color: {{ $activeCard['text'] }}; margin-top: 4px;">{{ $activeCard['desc'] }}</div>
            @endif
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 9: KEY MICROBES (one page each) --}}
{{-- ================================================================ --}}
@foreach($microbes as $microbe)
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">{{ $microbe['name'] }}</div>
    <div class="section-body">
        <table style="width: 100%; margin-bottom: 14px;" cellspacing="0" cellpadding="0">
            <tr>
                <td style="width: 48%; vertical-align: top;">
                    <div style="font-size: 12px; font-weight: bold; color: #301C47; margin-bottom: 6px;">Key Functions</div>
                    <ul style="padding-left: 16px; line-height: 1.7;">
                        @foreach($microbe['functions'] as $fn)
                            <li>{{ $fn }}</li>
                        @endforeach
                    </ul>
                </td>
                <td style="width: 4%;"></td>
                <td style="width: 48%; vertical-align: top;">
                    <div style="font-size: 12px; font-weight: bold; color: #301C47; margin-bottom: 6px;">Key Considerations</div>
                    <ul style="padding-left: 16px; line-height: 1.7;">
                        @foreach($microbe['considerations'] as $con)
                            <li>{{ $con }}</li>
                        @endforeach
                    </ul>
                </td>
            </tr>
        </table>

        @if($microbe['interpretation'])
            <p style="margin-bottom: 14px;">{{ $microbe['interpretation'] }}</p>
        @endif

        {{-- Range bar chart (div columns; DomPDF renders block backgrounds fine) --}}
        @php
            // Brand palette, shade = level (darker = higher, lighter = lower) — no
            // red/amber alarm colours. Matches the web Chart.js bars exactly.
            //   High  → #31356E (darkest indigo)   Target → #2D8BBA (medium blue)
            //   Low   → #6CE5E8 (light cyan)        Your Pet → #4168D5 (distinct blue)
            $barItems = [
                ['label' => 'Target', 'value' => $microbe['target'], 'color' => '#2D8BBA'],
                ['label' => 'High', 'value' => $microbe['high'], 'color' => '#31356E'],
                ['label' => 'Low', 'value' => $microbe['low'], 'color' => '#6CE5E8'],
                ['label' => 'Your Pet', 'value' => $microbe['value'], 'color' => '#4168D5'],
            ];
            $maxBarVal = max($microbe['target'], $microbe['high'], $microbe['low'], $microbe['value'], 1);
        @endphp
        <table style="width: 100%; margin-top: 10px;" cellspacing="0" cellpadding="0">
            <tr>
                @foreach($barItems as $bar)
                    <td style="width: 25%; text-align: center; padding: 2px 4px; font-size: 10px; font-weight: bold; color: #55505A; vertical-align: bottom;">{{ $bar['value'] }}%</td>
                @endforeach
            </tr>
            <tr>
                @foreach($barItems as $bar)
                    @php $barHeight = max(round(($bar['value'] / $maxBarVal) * 120), 4); @endphp
                    <td style="width: 25%; text-align: center; padding: 2px 4px; vertical-align: bottom; height: 130px;">
                        <div style="background-color: {{ $bar['color'] }}; width: 32px; height: {{ $barHeight }}px; margin: 0 auto;"></div>
                    </td>
                @endforeach
            </tr>
            <tr>
                @foreach($barItems as $bar)
                    <td style="width: 25%; text-align: center; padding: 4px; font-size: 10px; color: #55505A; border-top: 1px solid #e6e6ea;">{{ $bar['label'] }}</td>
                @endforeach
            </tr>
        </table>

        <div style="background-color: #E3F0FF; border-left: 4px solid #4654A4; padding: 8px 14px; font-size: 10px; font-style: italic; color: #301C47; margin-top: 12px;">See Veterinary Summary for clinical interpretation and recommendations.</div>
    </div>
</div>
@endforeach

{{-- ================================================================ --}}
{{-- SECTION 10: DIRECT LINKS --}}
{{-- ================================================================ --}}
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">Direct Links Between Microbes and Health/Disease</div>
    <div class="section-body">
        <p>The following microbial patterns have been associated with specific health outcomes in dogs. These links provide insight into potential risks and underlying imbalances within your dog's gut microbiome.</p>
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 11: HEALTH INSIGHTS --}}
{{-- ================================================================ --}}
<div class="section">
    <div class="section-bar">Microbiome-Driven Health Insights</div>
    <div class="section-body">
        {{-- Gut Wall Integrity gauge --}}
        <div style="border: 1px solid #e6e6ea; padding: 14px; margin-bottom: 14px; text-align: center; page-break-inside: avoid;">
            <div style="font-size: 13px; font-weight: bold; color: #301C47;">Gut Wall Integrity</div>
            <div style="font-size: 10px; color: #55505A; margin-bottom: 6px;">Measures the strength and resilience of the intestinal lining based on key bacterial markers.</div>
            <div style="text-align: center;">{!! ChartSvg::gauge($gwDeg) !!}</div>
            <div style="font-size: 18px; font-weight: bold; color: #301C47; margin-top: 4px;">{{ $gwScore }}</div>
            <table style="width: 220px; margin: 6px auto 0 auto;" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="text-align: left; font-size: 10px; color: #16a34a; font-weight: bold;">Low</td>
                    <td style="text-align: center; font-size: 10px; color: #d97706; font-weight: bold;">Target</td>
                    <td style="text-align: right; font-size: 10px; color: #dc2626; font-weight: bold;">High</td>
                </tr>
            </table>
        </div>

        <table style="width: 100%;" cellspacing="0" cellpadding="0">
            @foreach($insights as $i => $insight)
                @if($i % 2 === 0)<tr>@endif
                @php
                    $scoreVal = $insight['score'] ?? 'N/A';
                    $scoreBgColor = match($scoreVal) {
                        'Very High' => '#991b1b',
                        'High' => '#ef4444',
                        'Medium' => '#f59e0b',
                        'Low' => '#22c55e',
                        default => '#9ca3af',
                    };
                @endphp
                <td style="width: 48%; vertical-align: top; padding-bottom: 12px;">
                    <div style="border: 1px solid #e6e6ea; padding: 12px;">
                        <table style="width: 100%;" cellspacing="0" cellpadding="0">
                            <tr>
                                <td style="vertical-align: top;">
                                    <div style="font-size: 12px; font-weight: bold; color: #301C47;">{{ $insight['title'] }}</div>
                                    <div style="font-size: 9px; color: #55505A; margin-top: 3px;">{{ $insight['desc'] }}</div>
                                </td>
                                <td style="width: 78px; text-align: right; vertical-align: top;">
                                    <span style="background-color: {{ $scoreBgColor }}; color: #ffffff; padding: 3px 8px; font-weight: bold; font-size: 10px;">{{ $scoreVal }}</span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
                @if($i % 2 === 0)<td style="width: 4%;"></td>@endif
                @if($i % 2 === 1 || $i === count($insights) - 1)
                    @if($i % 2 === 0)<td style="width: 48%;"></td>@endif
                    </tr>
                @endif
            @endforeach
        </table>
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 12: RECOMMENDED NEXT STEPS (phased plan) --}}
{{-- Mirrors the web view's plan section; legacy flat list is the fallback. --}}
{{-- Hidden (both this and the legacy fallback below) when hide_subscribe is set, --}}
{{-- consistent with the web report — the commercial plan/subscribe pitch is the --}}
{{-- only thing suppressed; the clinical findings above remain. --}}
{{-- ================================================================ --}}
@if(! $report->hide_subscribe && $report->plan_id && $report->steps->isNotEmpty())
@php
    // PDF shows only a COMPACT summary + a "view online" block. The full
    // step-by-step protocol, product cards and subscription checkout live on the
    // interactive online report (avoids the multi-page step/card pagination).
    $planName = $report->plan?->name ?: 'a tailored plan';
    $reportUrl = $report->report_url; // public /report/{public_token} URL (App\Models\Report::getReportUrlAttribute)
    // UTM-tagged variant for the clickable "view online" links (the visible text
    // URL stays clean/short; only the hrefs carry the tracking params).
    $reportUrlCta = \App\Support\Utm::report($reportUrl, 'report_share', 'pdf_view_online');

    // One-line descriptor: distinct "Phase N" labels + total step count.
    $phaseCount = collect($report->steps)
        ->map(fn ($s) => preg_match('/Phase\s*(\d+)/i', (string) $s->stage_label, $m) ? (int) $m[1] : null)
        ->filter()->unique()->count();
    $stepCount = $report->steps->count();

    $snapshot = $report->subscription_snapshot ?? [];
    $subsGlobalRaw = Setting::get(Setting::SUBSCRIPTIONS_ENABLED);
    $subsGloballyEnabled = blank($subsGlobalRaw) ? true : filter_var($subsGlobalRaw, FILTER_VALIDATE_BOOLEAN);
    $subAvailable = $subsGloballyEnabled && (bool) data_get($snapshot, 'available', false);
    $subPrice = data_get($snapshot, 'price');
    // Full (pre-subscription) price + saving label, to convey old→new in the clause.
    $subFullPrice = data_get($snapshot, 'full_price');
    $subSaving = data_get($snapshot, 'saving_label');

    // Precompute the one-line descriptor clauses (avoids inline @if in markup).
    $phaseClause = $phaseCount > 0
        ? ' — ' . $phaseCount . ' phase' . ($phaseCount === 1 ? '' : 's') . ' across ' . $stepCount . ' guided step' . ($stepCount === 1 ? '' : 's')
        : '';
    $savingClause = (filled($subSaving) && filled($subFullPrice))
        ? ' — ' . $subSaving . ' the usual ' . $subFullPrice
        : (filled($subSaving) ? ' — ' . $subSaving : '');
    $subClause = ($subAvailable && filled($subPrice))
        ? ' and available as a subscription from ' . $subPrice . $savingClause
        : '';
@endphp
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">Recommended Next Steps</div>
    <div class="section-body">
        {{-- Compact summary + view-online block; kept together on one page. --}}
        <div style="page-break-inside: avoid;">
            <p style="margin-bottom: 16px;">Based on {{ $petName }}'s microbiome results we've built a personalised protocol, <b style="color: #301C47;">{{ $planName }}</b>{{ $phaseClause }}, with products and dosing matched to {{ $petName }}'s results{{ $subClause }}.</p>

            <table style="width: 100%; border-collapse: collapse;" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="background-color: #301C47; padding: 26px 28px; vertical-align: middle;">
                        <div style="font-size: 11px; color: #A99CC4; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; margin-bottom: 10px;">Your tailored plan is online</div>
                        <div style="font-size: 21px; font-weight: bold; color: #ffffff; margin-bottom: 10px;">View {{ $petName }}'s full plan &amp; subscription</div>
                        <div style="font-size: 11px; color: #cdbfe0; margin-bottom: 20px; line-height: 1.6;">The complete step-by-step protocol, recommended products with dosing, and one-click subscription are all available on {{ $petName }}'s secure online report.</div>
                        <a href="{{ $reportUrlCta }}" style="background-color: #4654A4; color: #ffffff; font-size: 13px; font-weight: bold; text-decoration: none; padding: 12px 24px; display: inline-block;">View plan online &raquo;</a>
                        <div style="font-size: 10px; color: #A99CC4; margin-top: 16px; word-break: break-all;"><a href="{{ $reportUrlCta }}" style="color: #9FC0E0; text-decoration: underline;">{{ $reportUrl }}</a></div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Kibble-diet nutritionist CTA (mirrors the web view's block). DomPDF-safe:
             single-cell table, tinted bg, square corners, border-left accent,
             inline-block link-button. No flexbox / emoji / inline SVG. --}}
        @if($report->recommendsNutritionist())
        <div style="page-break-inside: avoid; margin-top: 18px;">
            <table style="width: 100%; border-collapse: collapse;" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="background-color: #F3F8FC; border-left: 4px solid #4654A4; padding: 22px 24px; vertical-align: top;">
                        <div style="font-size: 11px; color: #4654A4; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; margin-bottom: 8px;">Nutrition support</div>
                        <div style="font-size: 17px; font-weight: bold; color: #301C47; margin-bottom: 8px;">We recommend speaking to a nutritionist</div>
                        <div style="font-size: 12px; color: #4b5563; line-height: 1.6; margin-bottom: 16px;">Pets on a kibble diet can benefit from tailored guidance on supporting gut health. Our nutritionists can help you build a plan suited to {{ $petName }}'s individual results.</div>
                        <a href="{{ \App\Support\Utm::report('https://biome4pets.com/nutritionists', 'nutritionist', 'nutritionist_cta') }}" style="background-color: #4654A4; color: #ffffff; font-size: 13px; font-weight: bold; text-decoration: none; padding: 12px 24px; display: inline-block;">View recommendations &raquo;</a>
                    </td>
                </tr>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ================================================================ --}}
{{-- SECTION 12b: MICROBIOME RESTORATION (legacy flat list) --}}
{{-- Fallback only when the phased plan above is NOT rendered. --}}
{{-- ================================================================ --}}
@elseif(! $report->hide_subscribe && $report->catalogProducts->count() > 0)
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">Microbiome Restoration</div>
    <div class="section-body">
        <h3>Product Protocol</h3>
        <p>Based on your dog's microbiome analysis, a structured and phased approach is recommended.</p>

        @foreach($report->catalogProducts as $index => $product)
            <div style="border: 1px solid #e6e6ea; padding: 14px; margin-bottom: 12px; page-break-inside: avoid;">
                <div style="font-size: 10px; color: #4654A4; font-weight: bold; margin-bottom: 4px;">Step {{ $index + 1 }}</div>
                <div style="font-size: 13px; font-weight: bold; color: #301C47; margin-bottom: 4px;">{{ $product->name }}</div>
                @if($product->description)
                    <div style="font-size: 11px; color: #55505A; margin-bottom: 6px;">{{ $product->description }}</div>
                @endif
                @if($product->url)
                    <div style="font-size: 10px; color: #4654A4; word-break: break-all;">{{ $product->url }}</div>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- ================================================================ --}}
{{-- SECTION 13: HELP AND CONTACTS --}}
{{-- ================================================================ --}}
<div style="page-break-before: always;"></div>
<div class="section">
    <div class="section-bar">Help and Contacts</div>
    <div class="section-body">
        {{-- Static report-text blocks: admin-editable in Settings → Report Text,
             resolved via ReportContent so the web report shows identical copy. --}}
        <h3>About This Report</h3>
        <p>{!! nl2br(e(ReportContent::reportAboutText())) !!}</p>

        <h3 style="margin-top: 16px;">Our Approach</h3>
        <ul style="padding-left: 18px; margin-bottom: 14px;">
            @foreach(ReportContent::reportApproachLines() as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>

        <h3>Support &amp; Next Steps</h3>
        <p>{!! nl2br(e(ReportContent::reportSupportText())) !!}</p>
    </div>
</div>

</body>
</html>
