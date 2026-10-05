<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $naslov }} - {{ config('app.name') }}</title>
</head>
<body>
    <header>
        <a href="{{ route('pocetna') }}">{{ config('app.name') }}</a>
        <nav><a href="{{ route('prilike.index') }}">Prilike</a></nav>
    </header>
    <main>
        @yield('sadrzaj')
    </main>
</body>
</html>
