@extends('emails.layout', ['title' => 'Reset your password', 'preheader' => 'Reset the password for your Biome4Pets portal account.'])

@section('content')
    <h1 style="margin:0 0 16px; font-size:23px; line-height:1.25; font-weight:800; color:#301C47;">Reset your password</h1>

    <p style="margin:0 0 16px;">We received a request to reset the password for your Biome4Pets portal account. Click the button below to choose a new one.</p>

    @include('emails._button', ['url' => $url, 'label' => 'Reset password'])

    <p style="margin:0 0 16px; font-size:14px; color:#55505A;">This link will expire in {{ $minutes }} minutes.</p>

    <p style="margin:16px 0 0;">If you did not request a password reset, no action is needed and your password will stay the same.</p>

    <p style="margin:20px 0 0;">Thanks,<br>The Biome4Pets team</p>
@endsection
