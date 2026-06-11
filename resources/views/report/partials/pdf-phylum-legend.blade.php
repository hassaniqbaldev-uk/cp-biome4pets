{{--
    PDF-only phylum legend (DomPDF). Renders a colour swatch + name + percentage
    table for a phylum map. Colours come from the shared App\Support\ReportContent
    so they match the web Chart.js legend.

    @param iterable $rows  phylum-name => percentage
--}}
<table style="width: 100%; margin-top: 10px;" cellspacing="0" cellpadding="0">
    @foreach($rows as $name => $pct)
        <tr>
            <td style="padding: 3px 4px; width: 14px; vertical-align: middle;">
                <div style="width: 10px; height: 10px; background-color: {{ \App\Support\ReportContent::phylumColor($name) }};"></div>
            </td>
            <td style="padding: 3px 4px; font-size: 10px; color: #55505A;">{{ $name }}</td>
            <td style="padding: 3px 4px; font-size: 10px; text-align: right; font-weight: bold; color: #301C47;">{{ $pct }}%</td>
        </tr>
    @endforeach
</table>
