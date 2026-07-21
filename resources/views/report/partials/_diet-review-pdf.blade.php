{{--
    The nutritionist diet-review recommendation card (PDF). DomPDF-safe: single-cell
    table, tinted bg, border-left accent, inline-block link (DomPDF renders <a href> as
    a real link). No flexbox / emoji / inline SVG. Pure markup — the recommendsDietReview()
    gate lives at each include site (Phase 1 slot + no-plan fallback). Copy shared with
    the web partial via app/Support/ReportContent.php; fully-qualified class refs so it
    works regardless of the parent view's `use` statements.
--}}
<div style="page-break-inside: avoid; margin-top: 18px;">
    <table style="width: 100%; border-collapse: collapse;" cellspacing="0" cellpadding="0">
        <tr>
            <td style="background-color: #F3F8FC; border-left: 4px solid #4654A4; padding: 22px 24px; vertical-align: top;">
                <div style="font-size: 11px; color: #4654A4; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; margin-bottom: 8px;">Nutrition support</div>
                <div style="font-size: 17px; font-weight: bold; color: #301C47; margin-bottom: 8px;">We recommend speaking to a nutritionist</div>
                <div style="font-size: 12px; color: #4b5563; line-height: 1.6; margin-bottom: 16px;">{{ \App\Support\ReportContent::dietReviewText() }}</div>
                <a href="{{ \App\Support\Utm::report(\App\Support\ReportContent::DIET_REVIEW_URL, 'nutritionist', 'diet_review_cta') }}" style="background-color: #4654A4; color: #ffffff; font-size: 13px; font-weight: bold; text-decoration: none; padding: 12px 24px; display: inline-block;">{{ \App\Support\ReportContent::dietReviewLinkLabel() }} &raquo;</a>
                <div style="font-size: 11px; color: #6b7280; line-height: 1.6; margin-top: 12px;">{{ \App\Support\ReportContent::dietReviewLoyaltyNote() }}</div>
            </td>
        </tr>
    </table>
</div>
