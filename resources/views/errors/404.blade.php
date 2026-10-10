@extends('javno.raspored', ['naslov' => '404'])

@section('sadrzaj')
    {{-- Tekst o prilici ostaje za prilike i za adrese bez strane; vodič i organizacija ga ne pominju. --}}
    <p class="prazno">{{ match (true) {
        request()->routeIs('vodici.show') => \App\Support\PrikazVodica::NEMA_STRANE,
        request()->routeIs('organizacije.show') => \App\Support\PrikazOrganizacija::NEMA_STRANE,
        default => \App\Support\PrikazPrilike::NEMA_STRANE,
    } }}</p>
@endsection
