@extends('emails.layout', ['title' => 'Welcome to Biome4Pets', 'preheader' => 'Your Biome4Pets portal account is ready — set your password to get started.'])

@section('content')
    <h1 style="margin:0 0 16px; font-size:23px; line-height:1.25; font-weight:800; color:#301C47;">Welcome to Biome4Pets{{ filled($name ?? null) ? ', '.e($name) : '' }}</h1>

    <p style="margin:0 0 16px;">An account has been created for you on the Biome4Pets portal. To keep your account secure, please set your own password using the button below, then log in.</p>

    @include('emails._button', ['url' => $url, 'label' => 'Set your password'])

    <p style="margin:0 0 16px; font-size:14px; color:#55505A;">For your security this link is single-use and expires after a short while. If it has expired, use the "Forgot password" link on the portal login page to request a fresh one.</p>

    <p style="margin:16px 0 0;">If you weren't expecting this account, you can safely ignore this email.</p>

    <p style="margin:20px 0 0;">Thanks,<br>The Biome4Pets team</p>
@endsection
