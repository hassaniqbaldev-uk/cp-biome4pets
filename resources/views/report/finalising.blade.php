{{--
    The public "being finalised" holding page. Served at a report's own public URL
    (ReportController::show / subscribe / downloadPdf) whenever the report is draft
    or has been unpublished for editing — so the link stays valid but no report
    content is shown. Branded to match report/show.blade.php (same navy #301C47 /
    accent #4654A4 palette + logo). Deliberately carries NO report data: no pet name,
    owner, metrics or token, so nothing leaks during an edit window. Re-publishing the
    report makes show() serve the real report again at this same URL.

    Styling is a self-contained <style> block (brand hex values inline) so the page
    renders identically regardless of which Tailwind utilities the compiled
    report.css happens to include. report.css is still loaded for the base font +
    reset and the shared favicon set.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Never index a holding page. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Report being finalised - Biome4Pets</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="stylesheet" href="{{ asset('css/report.css') }}">
    <style>
        :root {
            --fin-navy: #301C47;
            --fin-accent: #4654A4;
            --fin-light: #E3F0FF;
        }

        /* Full-height, gradient light background; content laid out top-bar → centred
           card → footer via a column flexbox. */
        .fin-body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(180deg, #ffffff 0%, var(--fin-light) 62%);
            color: var(--fin-navy);
            -webkit-font-smoothing: antialiased;
        }

        /* Top navy bar — mirrors the report header (white logo, same 54px height). */
        .fin-topbar { background: var(--fin-navy); }
        .fin-topbar__inner {
            max-width: 64rem;
            margin: 0 auto;
            padding: 1.15rem 1.5rem;
        }
        .fin-topbar__inner img { height: 54px; width: auto; display: block; }

        /* Centring region: the card sits in the vertical + horizontal middle of the
           space between the bar and the footer. */
        .fin-main {
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.75rem 1.25rem;
        }

        /* The clean white card. */
        .fin-card {
            width: 100%;
            max-width: 30rem;
            background: #ffffff;
            border: 1px solid rgba(48, 28, 71, 0.06);
            border-radius: 24px;
            padding: clamp(2rem, 5vw, 3rem) clamp(1.5rem, 4vw, 2.75rem);
            text-align: center;
            box-shadow:
                0 24px 60px -28px rgba(48, 28, 71, 0.35),
                0 3px 10px -6px rgba(48, 28, 71, 0.15);
            animation: fin-rise 0.6s cubic-bezier(0.16, 0.84, 0.44, 1) both;
        }

        /* Smaller, centred brand logo (was oversized) — sized to the header logo's
           scale rather than the hero's. */
        .fin-card__logo {
            height: 50px;
            width: auto;
            display: block;
            margin: 0 auto 1.5rem;
        }

        /* "In progress" status chip with a softly pulsing dot. */
        .fin-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.85rem;
            margin-bottom: 1.35rem;
            border-radius: 999px;
            background: rgba(70, 84, 164, 0.10);
            color: var(--fin-accent);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .fin-chip__dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: var(--fin-accent);
            animation: fin-pulse 1.6s ease-in-out infinite;
        }

        .fin-title {
            margin: 0 0 1rem;
            font-size: clamp(1.6rem, 5vw, 2.1rem);
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.01em;
            color: var(--fin-navy);
        }

        .fin-divider {
            display: block;
            width: 3.5rem;
            height: 4px;
            margin: 0 auto 1.5rem;
            border-radius: 999px;
            background: var(--fin-accent);
        }

        .fin-text {
            margin: 0 auto 1.75rem;
            max-width: 26rem;
            font-size: clamp(1rem, 2.5vw, 1.075rem);
            line-height: 1.65;
            color: rgba(48, 28, 71, 0.66);
        }

        /* Indeterminate "finalising" shimmer bar — the tasteful, alive cue. */
        .fin-progress {
            position: relative;
            width: 68%;
            max-width: 15rem;
            height: 6px;
            margin: 0 auto 1.85rem;
            border-radius: 999px;
            background: rgba(48, 28, 71, 0.08);
            overflow: hidden;
        }
        .fin-progress__bar {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 40%;
            border-radius: 999px;
            background: linear-gradient(90deg, transparent, var(--fin-accent), transparent);
            animation: fin-slide 1.8s ease-in-out infinite;
        }

        .fin-help {
            margin: 0;
            font-size: 0.85rem;
            color: rgba(48, 28, 71, 0.55);
        }
        .fin-link {
            color: var(--fin-accent);
            font-weight: 600;
            text-decoration: none;
        }
        .fin-link:hover { text-decoration: underline; }

        /* Footer contact band — mirrors the report footer. */
        .fin-footer {
            background: rgba(48, 28, 71, 0.92);
            color: #ffffff;
            font-size: 0.8rem;
        }
        .fin-footer__inner {
            max-width: 64rem;
            margin: 0 auto;
            padding: 0.9rem 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem 1rem;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .fin-footer__sep { opacity: 0.45; }

        @keyframes fin-rise {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: none; }
        }
        @keyframes fin-pulse {
            0%, 100% { transform: scale(1);    opacity: 1;   box-shadow: 0 0 0 0 rgba(70, 84, 164, 0.45); }
            50%      { transform: scale(0.82); opacity: 0.6; box-shadow: 0 0 0 6px rgba(70, 84, 164, 0); }
        }
        @keyframes fin-slide {
            0%   { transform: translateX(-100%); }
            100% { transform: translateX(250%); }
        }

        /* Respect reduced-motion: no entrance, no pulse, no shimmer. */
        @media (prefers-reduced-motion: reduce) {
            .fin-card,
            .fin-chip__dot,
            .fin-progress__bar { animation: none; }
            .fin-progress__bar { width: 100%; opacity: 0.5; }
        }

        /* Scale the logos + spacing down on narrow screens. */
        @media (max-width: 480px) {
            .fin-topbar__inner { padding: 1rem 1.15rem; }
            .fin-topbar__inner img { height: 44px; }
            .fin-card__logo { height: 42px; }
            .fin-main { padding: 2rem 1rem; }
        }
    </style>
</head>
<body class="fin-body">
    {{-- Top navy bar (mirrors the report header) --}}
    <header class="fin-topbar">
        <div class="fin-topbar__inner">
            <img src="/images/biome4pets-logo-white.png" alt="Biome4Pets">
        </div>
    </header>

    {{-- Centred holding card. Carries NO report data — generic message only. --}}
    <main class="fin-main">
        <div class="fin-card">
            <img
                src="/images/biome4pets-logo.png"
                alt="Biome4Pets - Microbiome Testing Service"
                class="fin-card__logo"
            >

            <span class="fin-chip">
                <span class="fin-chip__dot" aria-hidden="true"></span>
                Finalising
            </span>

            <h1 class="fin-title">This report is being finalised</h1>
            <span class="fin-divider" aria-hidden="true"></span>

            <p class="fin-text">
                We&rsquo;re putting the finishing touches to this microbiome profile.
                Please check back shortly &mdash; your report will be here at this same
                link once it&rsquo;s ready.
            </p>

            <div class="fin-progress" role="status" aria-label="Report is being finalised">
                <span class="fin-progress__bar" aria-hidden="true"></span>
            </div>

            <p class="fin-help">
                Questions? Email us at
                <a href="mailto:info@biome4pets.com" class="fin-link">info@biome4pets.com</a>.
            </p>
        </div>
    </main>

    {{-- Contact bar (mirrors the report footer band) --}}
    <footer class="fin-footer">
        <div class="fin-footer__inner">
            <span>info@biome4pets.com</span>
            <span class="fin-footer__sep" aria-hidden="true">&middot;</span>
            <span>www.biome4pets.com</span>
        </div>
    </footer>
</body>
</html>
