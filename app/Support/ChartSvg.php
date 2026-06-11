<?php

namespace App\Support;

/**
 * Server-side SVG charts for the report PDF (resources/views/report/pdf.blade.php).
 *
 * DomPDF cannot run JavaScript, so the web report's Chart.js charts do not exist
 * in the PDF. DomPDF also renders an inline <svg> as BLANK — it only rasterises
 * SVG delivered through an <img>. Every method here therefore returns a ready
 * <img src="data:image/svg+xml;base64,..."> tag. See docs/report-pdf.md.
 */
class ChartSvg
{
    /** Wrap raw SVG markup in the <img> data-URI DomPDF can actually render. */
    public static function img(string $svg, int $w, int $h): string
    {
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
            . '" width="' . $w . '" height="' . $h . '" alt="" style="display:inline-block;" />';
    }

    /**
     * Pie (or donut, when $holeRatio > 0) of a phylum-name => value map.
     * Colours come from the shared ReportContent map so it matches the web.
     */
    public static function pie(array $data, float $holeRatio = 0.0): string
    {
        $total = array_sum($data);
        $cx = 100; $cy = 100; $r = 92;
        $paths = '';

        if ($total <= 0) {
            $paths = '<circle cx="100" cy="100" r="92" fill="#eef1f4"/>';
        } else {
            $cum = -90; // start at 12 o'clock
            foreach ($data as $name => $value) {
                if ($value <= 0) {
                    continue;
                }
                $ang = ($value / $total) * 360;
                $s = deg2rad($cum);
                $e = deg2rad($cum + $ang);
                $x1 = $cx + $r * cos($s);
                $y1 = $cy + $r * sin($s);
                $x2 = $cx + $r * cos($e);
                $y2 = $cy + $r * sin($e);
                $large = $ang > 180 ? 1 : 0;
                $color = ReportContent::phylumColor((string) $name);

                if ($ang >= 359.99) {
                    // Single slice spanning the whole circle: draw as two arcs.
                    $xm = $cx + $r * cos(deg2rad($cum + 180));
                    $ym = $cy + $r * sin(deg2rad($cum + 180));
                    $paths .= sprintf(
                        '<path d="M%.2f,%.2f A%d,%d 0 1,1 %.2f,%.2f A%d,%d 0 1,1 %.2f,%.2f Z" fill="%s"/>',
                        $x1, $y1, $r, $r, $xm, $ym, $r, $r, $x1, $y1, $color
                    );
                } else {
                    $paths .= sprintf(
                        '<path d="M%d,%d L%.2f,%.2f A%d,%d 0 %d,1 %.2f,%.2f Z" fill="%s"/>',
                        $cx, $cy, $x1, $y1, $r, $r, $large, $x2, $y2, $color
                    );
                }
                $cum += $ang;
            }
            if ($holeRatio > 0) {
                $paths .= '<circle cx="100" cy="100" r="' . round($r * $holeRatio, 1) . '" fill="#ffffff"/>';
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="180" height="180">' . $paths . '</svg>';

        return self::img($svg, 180, 180);
    }

    /**
     * Semicircular gauge with green | amber | red bands and a needle at $deg
     * (0 = far left/Low, 180 = far right/High). Mirrors the web canvas gauge.
     */
    public static function gauge(float $deg): string
    {
        $cx = 110; $cy = 106; $r = 86; $sw = 18;
        $bands = [[180, 240, '#22c55e'], [240, 300, '#f59e0b'], [300, 360, '#ef4444']];

        $arcs = '';
        foreach ($bands as [$a, $b, $col]) {
            $sx = $cx + $r * cos(deg2rad($a));
            $sy = $cy + $r * sin(deg2rad($a));
            $ex = $cx + $r * cos(deg2rad($b));
            $ey = $cy + $r * sin(deg2rad($b));
            $arcs .= sprintf(
                '<path d="M%.2f,%.2f A%d,%d 0 0,1 %.2f,%.2f" fill="none" stroke="%s" stroke-width="%d"/>',
                $sx, $sy, $r, $r, $ex, $ey, $col, $sw
            );
        }

        $nd = 180 + $deg;
        $nx = $cx + ($r - 10) * cos(deg2rad($nd));
        $ny = $cy + ($r - 10) * sin(deg2rad($nd));
        $needle = sprintf('<line x1="%d" y1="%d" x2="%.2f" y2="%.2f" stroke="#301C47" stroke-width="3" stroke-linecap="round"/>', $cx, $cy, $nx, $ny);
        $dot = sprintf('<circle cx="%d" cy="%d" r="6" fill="#301C47"/>', $cx, $cy);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 220 120" width="220" height="120">' . $arcs . $needle . $dot . '</svg>';

        return self::img($svg, 220, 120);
    }

    /**
     * Horizontal diversity gradient slider (red | amber | green) with a navy
     * needle at $percent (0-100). Mirrors the web gradient slider.
     */
    public static function slider(float $percent): string
    {
        $W = 520; $H = 44; $barY = 4; $barH = 22;
        $percent = max(0, min(100, $percent));

        $segs  = sprintf('<rect x="0" y="%d" width="%.1f" height="%d" fill="#ef4444"/>', $barY, $W * 0.475, $barH);
        $segs .= sprintf('<rect x="%.1f" y="%d" width="%.1f" height="%d" fill="#f59e0b"/>', $W * 0.475, $barY, $W * 0.15, $barH);
        $segs .= sprintf('<rect x="%.1f" y="%d" width="%.1f" height="%d" fill="#22c55e"/>', $W * 0.625, $barY, $W * 0.375, $barH);

        $nx = $W * $percent / 100;
        $needle = sprintf('<rect x="%.1f" y="0" width="4" height="%d" fill="#301C47"/>', $nx - 2, $barH + $barY + 6);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '">' . $segs . $needle . '</svg>';

        return self::img($svg, $W, $H);
    }
}
