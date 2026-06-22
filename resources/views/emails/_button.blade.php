{{-- Bulletproof, table-based CTA button (renders in Outlook too). Vars: $url, $label. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
    <tr>
        <td align="center" bgcolor="#4654A4" style="border-radius:10px;">
            <a href="{{ $url }}" target="_blank" rel="noopener" style="display:inline-block; padding:14px 30px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:10px;">{{ $label }}</a>
        </td>
    </tr>
</table>
