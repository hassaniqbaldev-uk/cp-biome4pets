@extends('emails.layout', ['title' => 'Your pet\'s microbiome report', 'preheader' => 'Your pet\'s gut microbiome report is ready to view.'])

@section('content')
    <h1 style="margin:0 0 16px; font-size:23px; line-height:1.25; font-weight:800; color:#301C47;">{{ filled($petName ?? null) ? e($petName).'\'s report is ready' : 'Your report is ready' }}</h1>

    <p style="margin:0 0 16px;">{{ filled($ownerName ?? null) ? 'Hi '.e($ownerName).',' : 'Hi,' }}</p>

    <p style="margin:0 0 16px;">{{ filled($petName ?? null) ? e($petName).'\'s' : 'Your pet\'s' }} gut microbiome report is ready. Inside you'll find:</p>

    <ul style="margin:0 0 16px; padding-left:20px;">
        <li style="margin:0 0 6px;">Personalised microbiome insights</li>
        <li style="margin:0 0 6px;">Analysis of your pet's gut health</li>
        <li style="margin:0 0 6px;">Areas that may benefit from support</li>
        <li style="margin:0;">Recommended next steps based on the results</li>
    </ul>

    <p style="margin:0 0 16px;">We hope these insights help you make more informed decisions about your pet's health and comfort.</p>

    @include('emails._button', ['url' => $url, 'label' => 'See my results'])

    <p style="margin:20px 0 0;">Thanks,<br>The Biome4Pets team</p>
@endsection
