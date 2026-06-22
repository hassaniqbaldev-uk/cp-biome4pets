@extends('emails.layout', ['title' => 'SMTP test email', 'preheader' => 'Your Biome4Pets portal SMTP settings are working.'])

@section('content')
    <h1 style="margin:0 0 16px; font-size:23px; line-height:1.25; font-weight:800; color:#301C47;">SMTP test email</h1>

    <p style="margin:0 0 16px;">This is a test email confirming that your Biome4Pets portal SMTP settings are working.</p>

    <p style="margin:0 0 16px;">If you received this, outbound email is configured correctly.</p>

    <p style="margin:20px 0 0;">Thanks,<br>The Biome4Pets team</p>
@endsection
