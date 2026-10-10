@extends('javno.raspored', ['naslov' => \App\Support\PrikazPocetne::NASLOV_STRANE])

@section('hero')
    {{-- Otvorena vrata: luk rama, svetlo u otvoru, krilo otvoreno ka unutra koje prati luk i svetlo na podu; u svetlu su oznake vrsta koje imaju javnu priliku. --}}
    <svg class="vrata" viewBox="0 0 220 270" aria-hidden="true" focusable="false">
        <defs>
            <radialGradient id="vrata-oreol" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#f0994c" stop-opacity=".45"/><stop offset=".55" stop-color="#a85ff2" stop-opacity=".18"/><stop offset="1" stop-color="#a85ff2" stop-opacity="0"/></radialGradient>
            <linearGradient id="vrata-unutra" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe7b0"/><stop offset=".55" stop-color="#f7b866"/><stop offset="1" stop-color="#f0994c"/></linearGradient>
            <linearGradient id="vrata-krilo" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#e7b53c"/><stop offset="1" stop-color="#c85a14"/></linearGradient>
            <radialGradient id="vrata-pod" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#ffd9a0" stop-opacity=".75"/><stop offset="1" stop-color="#ffd9a0" stop-opacity="0"/></radialGradient>
            <filter id="vrata-senka" x="-20%" y="-40%" width="140%" height="190%"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#270f43" flood-opacity=".25"/></filter>
        </defs>
        <circle cx="110" cy="140" r="110" fill="url(#vrata-oreol)"/>
        <ellipse cx="96" cy="246" rx="92" ry="16" fill="url(#vrata-pod)"/>
        <path d="M52 242V118a58 58 0 0 1 116 0v124z" fill="url(#vrata-unutra)"/>
        @foreach ($vrste as $i => $vrsta)
            @php($x = [60, 76, 62, 78][$i % 4])
            @php($y = 100 + 26 * $i)
            <g filter="url(#vrata-senka)">
                <rect x="{{ $x }}" y="{{ $y }}" width="{{ 22 + 4.6 * mb_strlen($vrsta->getLabel()) }}" height="17" rx="8.5" fill="#fff" fill-opacity=".82" stroke="#fff" stroke-width=".6"/>
                <circle class="tacka" data-vrsta="{{ $vrsta->value }}" cx="{{ $x + 9 }}" cy="{{ $y + 8.5 }}" r="3.2"/>
                <text x="{{ $x + 16 }}" y="{{ $y + 11.6 }}" font-size="8" font-weight="700" fill="#270f43" font-family="Inter, system-ui, sans-serif">{{ $vrsta->getLabel() }}</text>
            </g>
        @endforeach
        <path d="M138 67.2A58 58 0 0 1 168 118v124h-30z" fill="url(#vrata-krilo)"/>
        <path d="M138 67.2V242" stroke="#fff" stroke-opacity=".55" stroke-width="1.2"/>
        <circle cx="144" cy="158" r="3" fill="#270f43"/>
        <path d="M40 242V116a70 70 0 0 1 140 0v126" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="5" stroke-linecap="round"/>
    </svg>
    <div class="sirina hero-sadrzaj">
        @if ($vrste !== [])
            <div class="hero-natpis">{{ implode(' · ', array_filter(array_map(fn ($vrsta) => \App\Support\PrikazPrilike::VRSTE_U_MNOZINI[$vrsta->value] ?? null, $vrste))) }}</div>
        @endif
        <h1>{{ \App\Support\PrikazPocetne::NASLOV }}<em>{{ \App\Support\PrikazPocetne::NASLOV_ISTAKNUTO }}</em></h1>
        <p>{{ \App\Support\PrikazPocetne::OPIS }}</p>
        <div class="hero-dugmad">
            <a class="dugme dugme-glavno" href="{{ route('prilike.index') }}">{{ \App\Support\PrikazPocetne::PRILIKE_DUGME }}</a>
            <a class="dugme dugme-staklo" href="{{ route('pomocnik.index') }}" data-pomocnik-otvori aria-controls="pomocnik-prozor">{{ \App\Support\PrikazPomocnika::PITAJ_VUDAR }}</a>
        </div>
        @include('javno.pretraga')
    </div>
@endsection

@section('sadrzaj')
    <ul class="plocice" aria-label="{{ \App\Support\PrikazPocetne::PLOCICE_NAZIV }}">
        @foreach ($plocice as $plocica)
            <li><a class="plocica" data-strana="{{ $plocica['strana'] }}" href="{{ $plocica['adresa'] }}"><b>{{ $plocica['broj'] }}</b> <span>{{ $plocica['tekst'] }}</span></a></li>
        @endforeach
    </ul>

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
