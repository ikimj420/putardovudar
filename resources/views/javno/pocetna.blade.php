@extends('javno.raspored', ['naslov' => \App\Support\PrikazPocetne::NASLOV_STRANE])

@section('hero')
    <div class="sirina hero-red">
        <div class="hero-tekst">
            <h1>{{ \App\Support\PrikazPocetne::NASLOV }}<em>{{ \App\Support\PrikazPocetne::NASLOV_ISTAKNUTO }}</em></h1>
            <p>{{ \App\Support\PrikazPocetne::OPIS }}</p>
        </div>
        <x-vrata />
        <a class="dugme dugme-staklo hero-pitaj" href="{{ route('pomocnik.index') }}" data-pomocnik-otvori aria-controls="pomocnik-prozor">{{ \App\Support\PrikazPomocnika::PITAJ_VUDAR }}</a>
    </div>
@endsection

@section('sadrzaj')
    {{-- Pretraga je prvo na strani, odmah ispod naslovnog dela (paket 64): ista forma kao na /prilike, u svetloj varijanti jer stoji na papiru. --}}
    @include('javno.pretraga', ['svetla' => true])

    <ul class="plocice" aria-label="{{ \App\Support\PrikazPocetne::PLOCICE_NAZIV }}">
        @foreach ($plocice as $plocica)
            <li><a class="plocica" data-strana="{{ $plocica['strana'] }}" href="{{ $plocica['adresa'] }}"><b>{{ $plocica['broj'] }}</b> <span>{{ $plocica['tekst'] }}</span></a></li>
        @endforeach
    </ul>

    @if ($prilikeUskoro->isNotEmpty())
        <section aria-labelledby="uskoro-naslov">
            <h2 class="naslov-izvora" id="uskoro-naslov">{{ \App\Support\PrikazPrilike::USKORO }}</h2>
            <div class="spisak tri">
                @foreach ($prilikeUskoro as $prilika)
                    @include('javno.kartica', ['prilika' => $prilika])
                @endforeach
            </div>
            @if ($imaViseUskoro)
                <p class="hero-vise"><a class="dugme dugme-glavno" href="{{ \App\Support\PrikazPrilike::adresaSpiska(uskoro: true) }}">{{ \App\Support\PrikazPocetne::SVE_USKORO_DUGME }}</a></p>
            @endif
        </section>
    @endif

    <section aria-labelledby="prilike-naslov">
        <h2 class="naslov-izvora" id="prilike-naslov">{{ \App\Support\PrikazPocetne::PRILIKE }}</h2>
        @forelse ($prilike as $prilika)
            @if ($loop->first)
                <div class="spisak tri">
            @endif

            @include('javno.kartica', ['prilika' => $prilika])

            @if ($loop->last)
                </div>
            @endif
        @empty
            <p class="prazno">{{ \App\Support\PrikazPrilike::NEMA_PRILIKA }}</p>
        @endforelse

        @if ($prilike->isNotEmpty())
            <p class="hero-vise"><a class="dugme dugme-glavno" href="{{ route('prilike.index') }}">{{ \App\Support\PrikazPocetne::SVE_PRILIKE_DUGME }}</a></p>
        @endif
    </section>

    @if ($imaVodica)
        <section class="vodici-traka" aria-labelledby="vodici-traka-naslov">
            <div class="traka-natpis">{{ \App\Support\PrikazPocetne::TRAKA_NATPIS }}</div>
            <h2 id="vodici-traka-naslov">{{ \App\Support\PrikazPocetne::TRAKA_NASLOV }}</h2>
            <p>{{ \App\Support\PrikazPocetne::TRAKA_OPIS }}</p>
            <a class="dugme dugme-glavno dugme-malo" href="{{ route('vodici.index') }}">{{ \App\Support\PrikazPocetne::TRAKA_DUGME }}</a>
        </section>
    @endif
@endsection
