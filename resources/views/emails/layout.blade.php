{{--
    Shared branded email layout for platform (transactional) emails.

    Email HTML is finicky: many clients strip <style>/CSS, and flexbox/grid are
    unreliable. So this is table-based with INLINE styles, web-safe fonts, a
    600px max width, and the COLOURED logo (works on the white email background).
    Both the welcome and password-reset emails @extend this, and future ones can
    too — one place for the brand chrome.

    Vars (all optional): $title (browser/preheader), $preheader (inbox preview).
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title ?? 'Biome4Pets' }}</title>
</head>
<body style="margin:0; padding:0; width:100%; background-color:#f4f4f7; -webkit-font-smoothing:antialiased; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

    {{-- Hidden inbox-preview text --}}
    @isset($preheader)
        <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f4f4f7;">{{ $preheader }}</div>
    @endisset

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f7;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#ffffff; border:1px solid #e7e3ef; border-radius:14px; overflow:hidden;">

                    {{-- Header: coloured logo on a light band (logo reads on white) --}}
                    <tr>
                        <td align="center" style="background-color:#ffffff; padding:28px 24px 12px;">
                            <img src="{{ url('/images/biome4pets-logo.png') }}" alt="Biome4Pets" width="168" style="display:block; width:168px; max-width:60%; height:auto; border:0; outline:none; text-decoration:none;">
                        </td>
                    </tr>

                    {{-- Accent rule in the brand blue --}}
                    <tr><td style="padding:0 32px;"><div style="height:3px; background-color:#4654A4; border-radius:3px;"></div></td></tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:28px 32px 8px; color:#301C47; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:24px 32px 30px;">
                            <div style="border-top:1px solid #eceaf2; padding-top:18px; color:#8a8595; font-size:12px; line-height:1.6; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                <p style="margin:0 0 4px;">Biome4Pets &middot; <a href="https://biome4pets.com" style="color:#4654A4; text-decoration:none;">biome4pets.com</a> &middot; <a href="mailto:info@biome4pets.com" style="color:#4654A4; text-decoration:none;">info@biome4pets.com</a></p>
                                <p style="margin:0;">This is an automated message about your Biome4Pets portal account. If you weren't expecting it, you can safely ignore this email.</p>
                            </div>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>
</body>
</html>
