{{-- Product thumbnail with the same letter-avatar fallback used in report/show.blade.php.
     Vars: $catalog (CatalogProduct|null), $size (int px, default 120). --}}
@php $sz = (int) ($size ?? 120); @endphp
@if($catalog?->image_path)
    <div style="flex:0 0 {{ $sz }}px; width:{{ $sz }}px; height:{{ $sz }}px; border-radius:12px; border:1px solid #e3e9ef; overflow:hidden; background:#f0f4f8;">
        <img src="{{ $catalog->image_path }}" alt="{{ $catalog->name }}" style="width:100%; height:100%; object-fit:cover; display:block;">
    </div>
@else
    <div style="flex:0 0 {{ $sz }}px; width:{{ $sz }}px; height:{{ $sz }}px; border-radius:12px; background:#E3F0FF; display:flex; align-items:center; justify-content:center;">
        <div class="bg-navy" style="width:{{ (int) round($sz * 0.42) }}px; height:{{ (int) round($sz * 0.42) }}px; border-radius:9999px; display:flex; align-items:center; justify-content:center;">
            <span class="text-white" style="font-size:{{ (int) round($sz * 0.18) }}px; font-weight:700;">{{ strtoupper(substr($catalog?->name ?: '?', 0, 1)) }}</span>
        </div>
    </div>
@endif
