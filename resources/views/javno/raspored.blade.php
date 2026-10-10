<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $naslov }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/javno.css') }}?v={{ filemtime(public_path('css/javno.css')) }}">
    <script src="{{ asset('js/javno.js') }}?v={{ filemtime(public_path('js/javno.js')) }}"></script>
</head>
{!! View::hasSection('traka') ? '<body class="ima-traku">' : '<body>' !!}
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
                    <a href="{{ route('prilike.index') }}">Prilike</a>
                    <a href="{{ route('vodici.index') }}">{{ \App\Support\PrikazVodica::NASLOV }}</a>
                    <a href="{{ route('organizacije.index') }}">{{ \App\Support\PrikazOrganizacija::NASLOV }}</a>
                    <a href="{{ route('kalendar.index') }}">{{ \App\Support\PrikazKalendara::NASLOV }}</a>
                </div>
                <div class="meni-desno">
                    <a class="asistent" href="{{ route('pomocnik.index') }}"@unless (request()->routeIs('pomocnik.*')) data-pomocnik-otvori aria-controls="pomocnik-prozor"@endunless>{{ \App\Support\PrikazPomocnika::NASLOV }}</a>
                </div>
            </div>
        </nav>
    </header>
    <main>
        @hasSection('traka')
            <section class="naslovna-traka">
                <div class="sirina">
                    @yield('traka')
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
