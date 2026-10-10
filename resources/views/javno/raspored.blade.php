<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $naslov }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/javno.css') }}?v={{ filemtime(public_path('css/javno.css')) }}">
</head>
<body>
    <header class="zaglavlje">
        <div class="sirina">
            <a class="logo" href="{{ route('pocetna') }}">{{ config('app.name') }}</a>
            <nav class="meni">
                <a href="{{ route('prilike.index') }}">Prilike</a>
                <a href="{{ route('vodici.index') }}">{{ \App\Support\PrikazVodica::NASLOV }}</a>
                <a href="{{ route('pomocnik.index') }}">{{ \App\Support\PrikazPomocnika::NASLOV }}</a>
            </nav>
        </div>
    </header>
    <main class="sirina sadrzaj">
        @yield('sadrzaj')
    </main>
</body>
</html>
