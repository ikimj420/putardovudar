@extends('javno.raspored', ['naslov' => $vodic->naslov])

@section('sadrzaj')
    <article class="prilika">
        <h1>{{ $vodic->naslov }}</h1>
        <p class="kratko">{{ $vodic->kratak_opis }}</p>
        <div class="sadrzaj-opis">{!! nl2br(e($vodic->tekst)) !!}</div>

        @if (count($vodic->koraci ?? []) > 0)
            <h2 class="naslov-izvora">{{ \App\Support\PrikazVodica::KORACI }}</h2>
            <ol class="koraci">
                @foreach ($vodic->koraci as $korak)
                    <li>{{ $korak }}</li>
                @endforeach
            </ol>
        @endif
    </article>
@endsection
