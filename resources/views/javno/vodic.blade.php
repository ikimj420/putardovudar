@extends('javno.raspored', ['naslov' => $vodic->naslov])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazVodica::NASLOV, route('vodici.index')], [$vodic->naslov, null]]])
    <h1>{{ $vodic->naslov }}</h1>
    <p class="kratko">{{ $vodic->kratak_opis }}</p>
@endsection

@section('sadrzaj')
    @include('javno.podeli', ['naslov' => $vodic->naslov, 'adresa' => route('vodici.show', $vodic->slug)])
    <article class="prilika detalj">
        <div class="ploca">
            <div class="sadrzaj-opis">{!! nl2br(e($vodic->tekst)) !!}</div>
        </div>

        @if (count($vodic->koraci ?? []) > 0)
            <div class="ploca">
                <h2 class="naslov-izvora">{{ \App\Support\PrikazVodica::KORACI }}</h2>
                <ol class="koraci">
                    @foreach ($vodic->koraci as $korak)
                        <li>{{ $korak }}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- Primeri jedan ispod drugog, svaki sa svojim „Kopiraj"; dugme se opisuje naslovom primera, da čitač ekrana razlikuje dugmad. --}}
        @foreach ($vodic->primeriZaPrikaz() as $primer)
            <div class="ploca">
                <h2 class="naslov-izvora" id="primer-naslov-{{ $loop->iteration }}">{{ $primer['naslov'] }}</h2>
                <div class="primer-tekst" id="primer-tekst-{{ $loop->iteration }}">{{ $primer['tekst'] }}</div>
                <button class="dugme-kopiraj" type="button" data-kopiraj="primer-tekst-{{ $loop->iteration }}" data-kopirano="{{ \App\Support\PrikazVodica::KOPIRANO }}" aria-describedby="primer-naslov-{{ $loop->iteration }}" aria-live="polite" hidden>{{ \App\Support\PrikazVodica::KOPIRAJ }}</button>
            </div>
        @endforeach

        @if ($sablon = $vodic->javniSablon())
            <div class="dugme-red">
                <a class="dugme-veza" href="{{ route('pomocnik.napisi.forma', $sablon->slug) }}" data-pomocnik-sablon>{{ \App\Support\PrikazPomocnika::NAPISI_MOJ }}</a>
            </div>
        @endif
    </article>
@endsection
