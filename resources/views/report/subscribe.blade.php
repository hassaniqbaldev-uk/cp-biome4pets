{{--
    Subscribe interstitial. Products come from the REPORT's OWN instantiated steps
    (variant-swapped), with the live plan supplying name/pricing
    (ReportController@subscribe). Frames the wait as "preparing your plan"
    with a progress bar, then AUTO-REDIRECTS to the report's resolved Loop checkout
    ($checkoutUrl = the variant-or-base url frozen on the report, with the live plan
    url as fallback) after 15s. The CTA still hands off immediately on click.
    Compacted for mobile (no header bar, thumbnail-beside-text layout).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Creating {{ $petName }}'s plan — Biome4Pets</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    {{-- Staff feedback widget only — hidden from public/customer viewers. --}}
    @auth
        @include('partials.feedbucket')
    @endauth
    <link rel="stylesheet" href="{{ asset('css/report.css') }}">
    <style>
        .sub-card { background:#fff; border:1px solid #E3F0FF; border-radius:22px; box-shadow:0 24px 60px -28px rgba(48,28,71,.35); padding:22px 20px; }
        .sub-eyebrow { font-size:11px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; color:#4654A4; margin-bottom:10px; }
        .sub-box { border:1px solid #E3F0FF; border-radius:16px; background:#FAFBFD; padding:16px 15px; }
        .sub-chip { display:inline-flex; align-items:center; gap:6px; background:#EEF5FB; color:#2f3b46; font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:999px; }
        .sub-cta { display:block; width:100%; text-align:center; font-weight:700; font-size:16px; text-decoration:none; padding:15px 18px; border-radius:12px; }

        /* Responsive type — compact on mobile, larger on wider screens. */
        .sub-title { font-size:23px; line-height:1.18; font-weight:800; margin:8px 0 8px; }
        .sub-lede { font-size:14px; color:#55505A; line-height:1.55; margin:0 0 20px; max-width:52ch; }

        /* First delivery — compact thumbnail-left / details-right row. */
        .first-row { display:flex; gap:14px; align-items:flex-start; }
        .first-main { flex:1; min-width:0; }
        .price-row { display:flex; align-items:baseline; flex-wrap:wrap; gap:8px; margin-top:10px; }
        .next-row { display:flex; gap:12px; align-items:center; }

        /* "Preparing your plan" — visible in-card progress bar + status, one unit. */
        .prep { margin:2px 0 22px; }
        .prep-status { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#4654A4; margin:0 0 9px; }
        .prep-dot { flex:0 0 auto; width:8px; height:8px; border-radius:50%; background:#4654A4; }
        .prep-track { height:8px; border-radius:999px; background:#E3F0FF; overflow:hidden; }
        .prep-fill { height:100%; width:0; background:#4654A4; border-radius:999px; }

        @media (prefers-reduced-motion: no-preference) {
            .prep-dot { animation:prep-pulse 1.1s ease-in-out infinite; }
        }
        @keyframes prep-pulse { 0%,100% { opacity:.35; } 50% { opacity:1; } }

        @media (min-width:560px) {
            .sub-card { padding:38px 36px; }
            .sub-title { font-size:30px; line-height:1.15; }
            .sub-lede { font-size:15px; line-height:1.6; }
        }
    </style>
</head>
<body class="bg-light-grey text-gray-800">

    {{-- Header removed for mobile height; a compact back link replaces it. --}}
    <main class="max-w-2xl mx-auto px-4 sm:px-6" style="padding-top:18px; padding-bottom:48px;">
        <div style="margin-bottom:12px;">
            <a href="{{ route('report.show', ['token' => $report->public_token]) }}" style="font-size:13px; font-weight:600; color:#4654A4; text-decoration:none;">&larr; Back to report</a>
        </div>

        <div class="sub-card">

            {{-- Plan badge --}}
            <span style="display:inline-block; background:#4654A4; color:#fff; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; padding:5px 12px; border-radius:999px;">{{ $plan->name }}</span>

            <h1 class="text-navy sub-title" style="margin-top:14px;">We're creating {{ $petName }}'s bespoke plan</h1>
            <p class="sub-lede">One plan that adapts to {{ $petName }} every month — we switch to the right supplement at each phase, so there's nothing to reorder or remember.</p>

            {{-- "Preparing your plan" — visible progress bar + cycling status as one
                 unit; fills over 15s, then the page auto-redirects to checkout. --}}
            <div class="prep">
                <div class="prep-status">
                    <span class="prep-dot"></span>
                    <span id="prep-status">Preparing {{ $petName }}'s plan…</span>
                </div>
                <div class="prep-track" role="progressbar" aria-label="Preparing {{ $petName }}'s plan"><div class="prep-fill" id="prep-bar"></div></div>
            </div>

            {{-- First delivery — thumbnail left, details (name, phase, price) right --}}
            @if($firstProduct)
                @php $firstCatalog = $firstProduct->catalogProduct; @endphp
                <div class="sub-box" style="margin-bottom:18px;">
                    <div class="sub-eyebrow">Your first delivery</div>
                    <div class="first-row">
                        @include('report.partials._product-thumb', ['catalog' => $firstCatalog, 'size' => 84])
                        <div class="first-main">
                            <h3 class="text-navy" style="font-size:17px; font-weight:700; margin:0 0 5px;">{{ $firstCatalog?->name ?? 'First supplement' }}</h3>
                            @if(filled($firstStep?->stage_label))
                                <div style="display:inline-block; background:#E3F0FF; color:#2f5d86; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:999px;">{{ $firstStep->stage_label }}</div>
                            @endif

                            {{-- Pricing sits beside the thumbnail to keep the card compact --}}
                            <div class="price-row">
                                @if(filled($plan->subscription_full_price))
                                    <span style="font-size:15px; font-weight:600; color:#9aa3ad; text-decoration:line-through;">{{ $plan->subscription_full_price }}</span>
                                @endif
                                @if(filled($plan->subscription_price))
                                    <span class="text-navy" style="font-size:23px; font-weight:800; line-height:1;">{{ $plan->subscription_price }}</span>
                                @endif
                                @if(filled($plan->subscription_saving_label))
                                    <span style="background:#4654A4; color:#fff; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px;">{{ $plan->subscription_saving_label }}</span>
                                @endif
                            </div>

                            @if(filled($firstProduct->duration) || filled($firstProduct->quantity))
                                <p style="font-size:12.5px; color:#55505A; margin:7px 0 0;">
                                    @if(filled($firstProduct->duration))<b class="text-navy">Duration:</b> {{ $firstProduct->duration }}@endif
                                    @if(filled($firstProduct->duration) && filled($firstProduct->quantity)) · @endif
                                    @if(filled($firstProduct->quantity))<b class="text-navy">Qty:</b> {{ $firstProduct->quantity }}@endif
                                </p>
                            @endif
                        </div>
                    </div>
                    @if(filled($plan->subscription_billing_note))
                        <p style="font-size:12px; color:#7a7580; margin:12px 0 0;">{{ $plan->subscription_billing_note }}</p>
                    @endif
                </div>
            @endif

            {{-- What comes next --}}
            @if($upcomingSteps->isNotEmpty())
                <div style="margin-bottom:20px;">
                    <div class="sub-eyebrow">What comes next</div>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        @foreach($upcomingSteps as $step)
                            @php $nextProduct = $step->products->first(); $nextCatalog = $nextProduct?->catalogProduct; @endphp
                            <div class="next-row" style="border:1px solid #eef2f6; border-radius:14px; padding:10px 12px; background:#fff;">
                                @include('report.partials._product-thumb', ['catalog' => $nextCatalog, 'size' => 48])
                                <div style="flex:1; min-width:0;">
                                    {{-- Report steps use `title`; the live-plan fallback uses `step_title`. --}}
                                    <div class="text-navy" style="font-size:14.5px; font-weight:700;">{{ $nextCatalog?->name ?? $step->title ?? $step->step_title }}</div>
                                    @if(filled($step->stage_label))
                                        <div style="font-size:12px; color:#7a7580; margin-top:2px;">{{ $step->stage_label }}</div>
                                    @endif
                                </div>
                                <div style="flex:0 0 auto; color:#9aa3ad; font-size:18px;">&rarr;</div>
                            </div>
                        @endforeach
                    </div>
                    <p style="font-size:12px; color:#7a7580; margin:9px 0 0;">Each phase ships automatically — we switch {{ $petName }} to the right supplement at the right time.</p>
                </div>
            @endif

            {{-- Assurance chips --}}
            <div style="display:flex; gap:9px; flex-wrap:wrap; margin-bottom:16px;">
                <span class="sub-chip">✓ Pause or cancel anytime</span>
                <span class="sub-chip">✓ Secure checkout</span>
            </div>

            {{-- Social proof: the customer COUNT, not a star rating (client asked to
                 show how many pet owners, not a review score). The count is
                 admin-editable in Settings → Plans / Generation → Reviews. --}}
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:18px;">
                <span style="font-size:14px; color:#55505A;">Join <b class="text-navy">{{ $reviewCount }}</b> Happy Pet Owners</span>
            </div>

            {{-- CTA — same tab (this is the checkout); clicking goes immediately,
                 without waiting for the 15s timer. The label states the auto-redirect;
                 the arrow + button styling keep it an obvious, clickable button.
                 NOTE: the Loop checkout URL is left CLEAN (no UTMs) so nothing can
                 interfere with Loop/Shopify checkout. The report→interstitial link
                 carries the UTM attribution instead (see show.blade.php subscribeHref). --}}
            <a href="{{ $checkoutUrl }}" id="sub-cta" class="sub-cta bg-teal text-white" style="background:#4654A4;">You'll be redirected automatically &rarr;</a>

            {{-- Loop-match honesty --}}
            <p style="font-size:12px; color:#9aa3ad; text-align:center; margin:10px 0 0;">Your exact basket and total are confirmed at checkout.</p>
        </div>
    </main>

    {{-- Footer --}}
    <footer class="bg-navy text-white">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-6 text-center">
            <img src="/images/biome4pets-logo-white.png" alt="Biome4Pets" style="height:40px; width:auto; display:inline-block;">
        </div>
    </footer>

    {{-- Auto-redirect: fill the bar over 15s, cycle status copy, then hand off to
         the live Loop checkout (same tab). Clicking the CTA navigates immediately.
         Reduced-motion users skip the animation but are still redirected. --}}
    <script>
        (function () {
            var target = @json($checkoutUrl);
            if (!target) return; // Guard: no checkout URL → never auto-redirect.

            var DURATION = 15000;
            var petName = @json($petName);
            var bar = document.getElementById('prep-bar');
            var status = document.getElementById('prep-status');

            var messages = [
                'Preparing ' + petName + "'s plan…",
                'Matching supplements to ' + petName + "'s results…",
                'Setting up the first delivery…',
                'Almost ready…',
            ];

            var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // Cycle the status copy across the duration.
            var step = 0;
            var msgTimer = setInterval(function () {
                step = Math.min(step + 1, messages.length - 1);
                if (status) status.textContent = messages[step];
            }, DURATION / messages.length);

            // Fill the progress bar (smooth, unless reduced motion → jump to full).
            if (bar) {
                if (reduceMotion) {
                    bar.style.width = '100%';
                } else {
                    requestAnimationFrame(function () {
                        bar.style.transition = 'width ' + DURATION + 'ms linear';
                        bar.style.width = '100%';
                    });
                }
            }

            // Hand off to checkout after the duration (same tab).
            setTimeout(function () {
                clearInterval(msgTimer);
                window.location.href = target;
            }, DURATION);
        })();
    </script>
</body>
</html>
