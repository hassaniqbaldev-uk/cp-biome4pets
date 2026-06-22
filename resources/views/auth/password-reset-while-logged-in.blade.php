<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>You're already signed in — Biome4Pets</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <style>
        body { margin:0; background:#f4f4f7; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#301C47; }
        .wrap { max-width:520px; margin:0 auto; padding:48px 16px; }
        .card { background:#fff; border:1px solid #e7e3ef; border-radius:14px; overflow:hidden; }
        .card__head { text-align:center; padding:28px 24px 12px; }
        .card__head img { width:168px; max-width:60%; height:auto; display:inline-block; }
        .accent { height:3px; background:#4654A4; border-radius:3px; margin:0 32px; }
        .card__body { padding:26px 32px 8px; font-size:15px; line-height:1.6; }
        .card__body h1 { font-size:22px; line-height:1.25; font-weight:800; margin:0 0 14px; }
        .card__body p { margin:0 0 14px; }
        .actions { margin:22px 0 4px; }
        .btn { display:block; width:100%; box-sizing:border-box; text-align:center; font-weight:bold; font-size:16px; text-decoration:none; padding:14px 18px; border-radius:10px; border:0; cursor:pointer; }
        .btn--primary { background:#4654A4; color:#fff; }
        .btn--link { display:inline-block; color:#4654A4; text-decoration:none; font-weight:600; }
        .muted { color:#8a8595; font-size:13px; }
        .foot { padding:18px 32px 30px; }
        .foot__inner { border-top:1px solid #eceaf2; padding-top:18px; color:#8a8595; font-size:12px; line-height:1.6; }
        .foot__inner a { color:#4654A4; text-decoration:none; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="card__head">
                <img src="{{ url('/images/biome4pets-logo.png') }}" alt="Biome4Pets">
            </div>
            <div class="accent"></div>

            <div class="card__body">
                <h1>You're already signed in</h1>

                <p>This is a "set your password" link, which only works when you're signed out. You're currently signed in to the Biome4Pets portal, so to set the password for that account you'll need to <strong>log out first</strong>, then open the link again.</p>

                <p class="muted">Note: the link in your email is single-use and may have since expired. If you log out and the link no longer works, request a fresh one with "Forgot password" on the login page.</p>

                <div class="actions">
                    <form method="POST" action="{{ $logoutUrl }}">
                        @csrf
                        <button type="submit" class="btn btn--primary">Log out</button>
                    </form>
                </div>

                <p style="margin-top:16px;">
                    <a class="btn--link" href="{{ $forgotUrl }}">Forgot password</a>
                    <span class="muted">&nbsp;·&nbsp;</span>
                    <a class="btn--link" href="{{ $loginUrl }}">Back to login</a>
                </p>
            </div>

            <div class="foot">
                <div class="foot__inner">
                    <p style="margin:0 0 4px;">Biome4Pets &middot; <a href="https://biome4pets.com">biome4pets.com</a> &middot; <a href="mailto:info@biome4pets.com">info@biome4pets.com</a></p>
                    <p style="margin:0;">If you weren't expecting this, you can safely ignore it.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
