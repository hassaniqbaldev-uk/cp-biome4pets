# The report renders in TWO templates (web + PDF)

The pet microbiome report is produced by **two separate Blade templates**:

| Output | Template | Engine |
| ------ | -------- | ------ |
| Web view (`/report/{slug}`) | `resources/views/report/show.blade.php` | Browser + Tailwind CSS + Chart.js |
| PDF download (`/report/{slug}/pdf`, the "Download PDF" button) | `resources/views/report/pdf.blade.php` | [DomPDF](https://github.com/dompdf/dompdf) via `barryvdh/laravel-dompdf` |

Both are served from `App\Http\Controllers\ReportController` (`show()` and `downloadPdf()`),
which eager-load the **same** relations so the two outputs have the same data available.

The web view is the **visual reference**. The PDF aims to match it as closely as
DomPDF allows.

## Why two templates (and not one)

DomPDF is an HTML-to-PDF renderer with hard limits:

- **No JavaScript.** The web report draws every chart with Chart.js on `<canvas>`.
  None of that runs in the PDF.
- **No Flexbox or CSS Grid.** Layout in the PDF must use `<table>` and
  `inline-block` only.
- **No Tailwind.** The compiled Tailwind stylesheet targets the browser; DomPDF
  cannot consume it. The PDF uses plain inline styles / a small `<style>` block.

So the templates **cannot share styling**. They are deliberately kept separate.

> ⚠️ **If you change report content or layout, UPDATE BOTH TEMPLATES.**
> A banner comment at the top of each `.blade.php` file says the same thing.

## How we reduce drift

Although styling can't be shared, the underlying **data** is. It lives in
`app/Support/ReportContent.php` and is consumed by both templates:

- `ReportContent::microbes($report)` — the 5 key microbes (functions,
  considerations, healthy ranges, the report's per-phylum AI interpretation).
- `ReportContent::insights($report)` — the 6 health-insight scores + copy.
- `ReportContent::HEALTHY_DOG_PHYLA` — the "healthy dog" comparison baseline.
- `ReportContent::PHYLUM_COLORS` / `phylumColor()` — the canonical phylum colour
  map, shared by the web Chart.js charts and the PDF's SVG charts so the two
  documents always agree on colour.
- `ReportContent::topPhyla($phylumData)` — top-6 phyla + grouped "Other".

Change a microbe's copy, an insight description, the baseline, or a phylum colour
in **one place** and both templates pick it up. Threshold/label logic for the
overview scores (Diversity / Species Richness / Dysbiosis) is short and
style-coupled, so it stays inline in each template — but the thresholds are
identical and must be kept that way.

## How charts work in the PDF

This is the part most likely to surprise you.

**DomPDF renders SVG only through an `<img>` tag.** An inline `<svg>…</svg>` in
the HTML body renders **BLANK**. The same SVG wrapped as
`<img src="data:image/svg+xml;base64,…">` renders correctly. (Verified against
DomPDF 3.1.5 / php-svg-lib 1.0.2.)

All PDF charts are therefore built **server-side** in `app/Support/ChartSvg.php`,
which returns a ready `<img>` data-URI:

- `ChartSvg::pie($data, $holeRatio = 0)` — pie, or a donut when `$holeRatio > 0`
  (used for "Your Dog", "Healthy Dog", and the Phylum Distribution donut).
- `ChartSvg::gauge($deg)` — the semicircular Gut Wall Integrity gauge
  (green / amber / red bands + needle).
- `ChartSvg::slider($percent)` — the diversity gradient slider.

The per-microbe range "bar chart" is drawn with plain `<div>` blocks (coloured
backgrounds with pixel heights), which DomPDF renders natively — no SVG needed.

**Product images:** catalog product images are remote (Shopify CDN) URLs and
DomPDF's `enable_remote` is off, so the PDF does **not** fetch them. Product
cards show a brand letter-avatar placeholder instead (the same fallback the web
view uses when an image is missing).

## Brand palette (PDF)

| Use | Colour |
| --- | ------ |
| Section bars, headings, footer | dark purple `#301C47` |
| Accents, bars, borders | blue `#4E7BA4` |
| Body text | `#55505A` |
| Pale-blue panels | `#E3F0FF` |
| Lavender panels | `#FAF8FF` |

## Verifying the PDF locally

```php
// tinker
$report = App\Models\Report::where('slug', 'mario-hnd229')
    ->with(['client','pet.client','plan','catalogProducts','steps.products.catalogProduct'])
    ->first();
$pdf = Barryvdh\DomPDF\Facade\Pdf::loadView('report.pdf', compact('report'))->setPaper('a4','portrait');
file_put_contents(storage_path('app/report.pdf'), $pdf->output());
```

Then open `storage/app/report.pdf`. (In the browser, just click **Download PDF**
on the report page, or hit `/report/{slug}/pdf`.) For quick page-1 spot checks on
macOS without poppler installed: `sips -s format png report.pdf --out report.png`.
