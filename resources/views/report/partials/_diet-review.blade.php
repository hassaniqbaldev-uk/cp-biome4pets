{{--
    The nutritionist diet-review recommendation card (WEB). Pure markup — the
    recommendsDietReview() gate lives at each include site, so this can render BOTH
    inside Phase 1 and as the no-plan fallback without duplicating the block. Copy +
    link + loyalty note come from Settings via app/Support/ReportContent.php (shared
    with the PDF partial). Fully-qualified class refs so it works in any include scope.
--}}
<div style="background:#F3F8FC; border:1px solid #D9E6F2; border-left:4px solid #4654A4; border-radius:14px; padding:22px 24px;">
    <div style="display:flex; align-items:flex-start; gap:14px;">
        <div style="flex:0 0 auto; width:40px; height:40px; border-radius:9999px; background:#E3F0FF; display:flex; align-items:center; justify-content:center;">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4654A4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/></svg>
        </div>
        <div>
            <h3 style="font-size:17px; font-weight:700; color:#301C47; margin:0 0 6px;">We recommend speaking to a nutritionist</h3>
            <p style="font-size:14px; color:#4b5563; line-height:1.6; margin:0 0 16px; max-width:60ch;">{{ \App\Support\ReportContent::dietReviewText() }}</p>
            <a href="{{ \App\Support\Utm::report(\App\Support\ReportContent::DIET_REVIEW_URL, 'nutritionist', 'diet_review_cta') }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; background:#4654A4; color:#fff; font-weight:600; font-size:14px; text-decoration:none; padding:11px 22px; border-radius:9px;">{{ \App\Support\ReportContent::dietReviewLinkLabel() }} &rarr;</a>
            <p style="font-size:13px; color:#6b7280; line-height:1.6; margin:12px 0 0;">{{ \App\Support\ReportContent::dietReviewLoyaltyNote() }}</p>
        </div>
    </div>
</div>
