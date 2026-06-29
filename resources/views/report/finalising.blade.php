{{--
    The public "being finalised" holding page. Served at a report's own public URL
    (ReportController::show / subscribe / downloadPdf) whenever the report is draft
    or has been unpublished for editing — so the link stays valid but no report
    content is shown. Branded to match report/show.blade.php (same report.css, navy
    + teal palette, logo). Deliberately carries NO report data: no pet name, owner,
    metrics or token, so nothing leaks during an edit window. Re-publishing the
    report makes show() serve the real report again at this same URL.
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
</head>
<body class="bg-light-blue min-h-screen flex flex-col">
    {{-- Top navy bar (mirrors the report header) --}}
    <header class="bg-navy text-white">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 sm:py-6">
            <img src="/images/biome4pets-logo-white.png" alt="Biome4Pets" style="height:54px; width:auto; display:block;">
        </div>
    </header>

    {{-- Friendly holding message --}}
    <main class="flex-1 bg-gradient-to-b from-white/50 to-light-blue">
        <div class="max-w-xl mx-auto px-4 sm:px-6 py-16 sm:py-24 text-center">
            <img
                src="/images/biome4pets-logo.png"
                alt="Biome4Pets - Microbiome Testing Service"
                class="mx-auto w-40 sm:w-48 h-auto mb-8 sm:mb-10"
            />

            <h1 class="text-3xl sm:text-4xl font-extrabold text-navy tracking-tight mb-5">
                This report is being finalised
            </h1>
            <div class="mx-auto h-1 w-16 bg-teal rounded-full mb-8"></div>

            <p class="text-base sm:text-lg text-navy/70 leading-relaxed">
                We&rsquo;re putting the finishing touches to this microbiome profile.
                Please check back shortly &mdash; your report will be here at this same link
                once it&rsquo;s ready.
            </p>

            <p class="mt-8 text-sm text-navy/55">
                Questions? Email us at
                <a href="mailto:info@biome4pets.com" class="text-teal font-semibold">info@biome4pets.com</a>.
            </p>
        </div>
    </main>

    {{-- Contact bar (mirrors the report footer band) --}}
    <footer class="bg-navy/90 text-white text-xs sm:text-sm">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:justify-center gap-1 sm:gap-6 text-center">
            <span>info@biome4pets.com</span>
            <span class="hidden sm:inline">&middot;</span>
            <span>www.biome4pets.com</span>
        </div>
    </footer>
</body>
</html>
