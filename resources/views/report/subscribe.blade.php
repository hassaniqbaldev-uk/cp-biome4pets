{{--
    Subscribe interstitial. Server-rendered, fully dynamic from the LIVE plan
    (ReportController@subscribe). Explains the auto-adjusting plan before the CTA
    hands off to the plan's Loop checkout (plan->subscription_url). CTA-only — no
    demo plan-selector and no auto-redirect countdown.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start {{ $petName }}'s plan — Biome4Pets</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    @include('partials.feedbucket')
    <link rel="stylesheet" href="{{ asset('css/report.css') }}">
    <style>
        .sub-card { background:#fff; border:1px solid #E3F0FF; border-radius:22px; box-shadow:0 24px 60px -28px rgba(48,28,71,.35); padding:30px 26px; }
        .sub-eyebrow { font-size:11px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; color:#4E7BA4; margin-bottom:12px; }
        .sub-box { border:1px solid #E3F0FF; border-radius:16px; background:#FAFBFD; padding:20px 18px; }
        .sub-chip { display:inline-flex; align-items:center; gap:6px; background:#EEF5FB; color:#2f3b46; font-size:13px; font-weight:600; padding:7px 13px; border-radius:999px; }
        .sub-cta { display:block; width:100%; text-align:center; font-weight:700; font-size:16px; text-decoration:none; padding:16px 18px; border-radius:12px; }
        @media (min-width:560px){ .sub-card{ padding:38px 36px; } }
        .first-row { display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap; }
        .next-row { display:flex; gap:14px; align-items:center; }
    </style>
</head>
<body class="bg-light-grey text-gray-800">

    {{-- Header --}}
    <header class="bg-navy text-white">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-5 flex items-center justify-between gap-3">
            <img src="/images/biome4pets-logo-white.png" alt="Biome4Pets" style="height:46px; width:auto; display:block;">
            <a href="{{ route('report.show', ['slug' => $report->slug]) }}" style="font-size:13px; font-weight:600; color:rgba(255,255,255,.78); text-decoration:none;">&larr; Back to report</a>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 sm:px-6" style="padding-top:40px; padding-bottom:56px;">
        <div class="sub-card">

            {{-- Plan badge --}}
            <span style="display:inline-block; background:#4E7BA4; color:#fff; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; padding:5px 12px; border-radius:999px;">{{ $plan->name }}</span>

            <h1 class="text-navy" style="font-size:28px; line-height:1.15; font-weight:800; margin:16px 0 10px;">We're creating {{ $petName }}'s bespoke plan</h1>
            <p style="font-size:15px; color:#55505A; line-height:1.6; margin:0 0 26px; max-width:52ch;">One plan that adapts to {{ $petName }} every month — we switch to the right supplement at each phase, so there's nothing to reorder or remember.</p>

            {{-- First delivery --}}
            @if($firstProduct)
                @php $firstCatalog = $firstProduct->catalogProduct; @endphp
                <div class="sub-box" style="margin-bottom:22px;">
                    <div class="sub-eyebrow">Your first delivery</div>
                    <div class="first-row">
                        @include('report.partials._product-thumb', ['catalog' => $firstCatalog, 'size' => 116])
                        <div style="flex:1; min-width:200px;">
                            <h3 class="text-navy" style="font-size:19px; font-weight:700; margin:0 0 4px;">{{ $firstCatalog?->name ?? 'First supplement' }}</h3>
                            @if(filled($firstStep?->stage_label))
                                <div style="display:inline-block; background:#E3F0FF; color:#2f5d86; font-size:12px; font-weight:700; padding:3px 10px; border-radius:999px; margin:2px 0 10px;">{{ $firstStep->stage_label }}</div>
                            @endif
                            @if(filled($firstProduct->duration))
                                <p style="font-size:13px; color:#55505A; margin:3px 0;"><b class="text-navy">Duration:</b> {{ $firstProduct->duration }}</p>
                            @endif
                            @if(filled($firstProduct->quantity))
                                <p style="font-size:13px; color:#55505A; margin:3px 0;"><b class="text-navy">Quantity:</b> {{ $firstProduct->quantity }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Pricing --}}
                    <div style="margin-top:18px; padding-top:16px; border-top:1px solid #E3F0FF; display:flex; align-items:baseline; flex-wrap:wrap; gap:10px;">
                        @if(filled($plan->subscription_full_price))
                            <span style="font-size:16px; font-weight:600; color:#9aa3ad; text-decoration:line-through;">{{ $plan->subscription_full_price }}</span>
                        @endif
                        @if(filled($plan->subscription_price))
                            <span class="text-navy" style="font-size:26px; font-weight:800; line-height:1;">{{ $plan->subscription_price }}</span>
                        @endif
                        @if(filled($plan->subscription_saving_label))
                            <span style="background:#4E7BA4; color:#fff; font-size:12px; font-weight:700; padding:3px 11px; border-radius:999px;">{{ $plan->subscription_saving_label }}</span>
                        @endif
                    </div>
                    @if(filled($plan->subscription_billing_note))
                        <p style="font-size:12px; color:#7a7580; margin:8px 0 0;">{{ $plan->subscription_billing_note }}</p>
                    @endif
                </div>
            @endif

            {{-- What comes next --}}
            @if($upcomingSteps->isNotEmpty())
                <div style="margin-bottom:24px;">
                    <div class="sub-eyebrow">What comes next</div>
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        @foreach($upcomingSteps as $step)
                            @php $nextProduct = $step->products->first(); $nextCatalog = $nextProduct?->catalogProduct; @endphp
                            <div class="next-row" style="border:1px solid #eef2f6; border-radius:14px; padding:12px 14px; background:#fff;">
                                @include('report.partials._product-thumb', ['catalog' => $nextCatalog, 'size' => 54])
                                <div style="flex:1; min-width:0;">
                                    <div class="text-navy" style="font-size:15px; font-weight:700;">{{ $nextCatalog?->name ?? $step->step_title }}</div>
                                    @if(filled($step->stage_label))
                                        <div style="font-size:12px; color:#7a7580; margin-top:2px;">{{ $step->stage_label }}</div>
                                    @endif
                                </div>
                                <div style="flex:0 0 auto; color:#9aa3ad; font-size:18px;">&rarr;</div>
                            </div>
                        @endforeach
                    </div>
                    <p style="font-size:12px; color:#7a7580; margin:10px 0 0;">Each phase ships automatically — we switch {{ $petName }} to the right supplement at the right time.</p>
                </div>
            @endif

            {{-- Assurance chips --}}
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px;">
                <span class="sub-chip">✓ Pause or cancel anytime</span>
                <span class="sub-chip">✓ Secure checkout</span>
            </div>

            {{-- Reviews (PLACEHOLDER figures — set in ReportController::REVIEW_RATING / REVIEW_COUNT) --}}
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:22px;">
                <span style="color:#F5A623; font-size:18px; letter-spacing:2px;">★★★★★</span>
                <span style="font-size:14px; color:#55505A;"><b class="text-navy">{{ $reviewRating }}</b> · from {{ $reviewCount }} reviews</span>
            </div>

            {{-- CTA — same tab (this is the checkout) --}}
            <a href="{{ $plan->subscription_url }}" class="sub-cta bg-teal text-white" style="background:#4E7BA4;">Start {{ $petName }}'s plan &rarr;</a>

            {{-- Loop-match honesty --}}
            <p style="font-size:12px; color:#9aa3ad; text-align:center; margin:14px 0 0;">Your exact basket and total are confirmed at checkout.</p>
        </div>
    </main>

    {{-- Footer --}}
    <footer class="bg-navy text-white">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-6 text-center">
            <img src="/images/biome4pets-logo-white.png" alt="Biome4Pets" style="height:40px; width:auto; display:inline-block;">
        </div>
    </footer>
</body>
</html>
