@php($imeSajta = explode(' ', (string) config('app.name'), 2))
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
            <a class="logo" href="{{ route('pocetna') }}">{{ $imeSajta[0] }}@isset($imeSajta[1]) <span>{{ $imeSajta[1] }}</span>@endisset</a>
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
