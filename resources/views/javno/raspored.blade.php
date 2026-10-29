<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $naslov }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/javno.css') }}?v={{ filemtime(public_path('css/javno.css')) }}">
    <script src="{{ asset('js/javno.js') }}?v={{ filemtime(public_path('js/javno.js')) }}"></script>
</head>
{{-- Svaka strana ima svoju boju (paket 57): prilike narandžasta, vodiči ljubičasta, organizacije bobica, kalendar zlato; početna i pomoćnik su noć. --}}
{{-- „Stipendije" je veza ka spisku prilika sa izabranom vrstom (paket 80): oznaku „ovde si" nosi samo kad je ta vrsta zaista izabrana (spisak šalje $izabrana), a tada je „Prilike" nemaju. --}}
@php
    $strana = match (true) {
        request()->routeIs('prilike.*') => 'prilike',
        request()->routeIs('vodici.*') => 'vodici',
        request()->routeIs('organizacije.*') => 'organizacije',
        request()->routeIs('kalendar.*') => 'kalendar',
        default => null,
    };
    $stipendije = ($izabrana ?? null) === \App\Enums\VrstaPrilike::Stipendija;
    $atributiTela = trim(($strana ? 'data-strana="'.$strana.'" ' : '').(View::hasSection('traka') || View::hasSection('hero') ? 'class="ima-traku"' : ''));
@endphp
{!! '<body'.($atributiTela !== '' ? ' '.$atributiTela : '').'>' !!}
    <div class="linija" aria-hidden="true"></div>
    <header class="zaglavlje">
        <nav class="zaglavlje-red" aria-label="Glavni meni">
            <a class="logo" href="{{ route('pocetna') }}">
                {{-- Otvorena vrata: luk rama (boja slova zaglavlja), krilo i kvaka. --}}
                <svg class="logo-znak" width="34" height="34" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
                    <defs><linearGradient id="logo-krilo" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e07229"/><stop offset="1" stop-color="#e7b53c"/></linearGradient></defs>
                    <path d="M12 60V28C12 15 21 6 32 6s20 9 20 22v32" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>
                    <path d="M32 14l13 6v40H32z" fill="url(#logo-krilo)"/>
                    <circle cx="41" cy="40" r="2.6" fill="#270f43"/>
                </svg>
                <span class="logo-ime">{{ config('app.name') }} <small>{{ \App\Support\PrikazZaglavlja::SLOGAN }}</small></span>
            </a>
            <button class="dugme-meni" type="button" aria-label="{{ \App\Support\PrikazZaglavlja::OTVORI_MENI }}" aria-controls="meni" aria-expanded="false" hidden>☰</button>
            <div class="meni" id="meni">
                <div class="meni-sredina">
                    <a href="{{ route('prilike.index') }}"@if ($strana === 'prilike' && ! $stipendije) aria-current="page"@endif>Prilike</a>
                    <a href="{{ route('prilike.index', ['vrsta' => \App\Enums\VrstaPrilike::Stipendija->value]) }}"@if ($stipendije) aria-current="page"@endif>{{ \App\Support\PrikazZaglavlja::STIPENDIJE }}</a>
                    <a href="{{ route('vodici.index') }}"@if ($strana === 'vodici') aria-current="page"@endif>{{ \App\Support\PrikazVodica::NASLOV }}</a>
                    <a href="{{ route('organizacije.index') }}"@if ($strana === 'organizacije') aria-current="page"@endif>{{ \App\Support\PrikazOrganizacija::NASLOV }}</a>
                    <a href="{{ route('kalendar.index') }}"@if ($strana === 'kalendar') aria-current="page"@endif>{{ \App\Support\PrikazKalendara::NASLOV }}</a>
                </div>
            </div>
        </nav>
    </header>
    <main>
        {{-- Početna ima svoj naslovni deo (hero) sa vratima u svom rasporedu, ostale strane naslovnu traku; vrata su ista komponenta (paket 62). --}}
        @hasSection('hero')
            <section class="hero zrno">
                @yield('hero')
            </section>
        @endif
        @hasSection('traka')
            <section class="naslovna-traka">
                <div class="sirina traka-red">
                    <div class="traka-tekst">
                        @yield('traka')
                    </div>
                    <x-vrata />
                </div>
            </section>
        @endif
        <div class="sirina sadrzaj">
            @yield('sadrzaj')
        </div>
    </main>
    @unless (request()->routeIs('pomocnik.*'))
        @include('javno.pomocnik-prozor')
    @endunless
</body>
</html>
