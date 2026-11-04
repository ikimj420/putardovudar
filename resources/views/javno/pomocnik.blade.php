@extends('javno.raspored', ['naslov' => \App\Support\PrikazPomocnika::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazPomocnika::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazPomocnika::NASLOV }}</h1>
    <p class="uvod">{{ \App\Support\PrikazPomocnika::UVOD }}</p>
@endsection

@section('sadrzaj')
    <form class="pitanje" method="post" action="{{ route('pomocnik.pitaj') }}">
        @csrf
        <label for="pitanje">{{ \App\Support\PrikazPomocnika::OZNAKA_POLJA }}</label>
        <textarea id="pitanje" name="pitanje" rows="3" maxlength="{{ \App\Services\Pomocnik\Pomocnik::NAJVISE_ZNAKOVA_PITANJA }}" required placeholder="{{ \App\Support\PrikazPomocnika::PRIMER }}">{{ $pitanje ?? '' }}</textarea>
        @include('javno.pomocnik-natpis')
        @isset($greska)
            <p class="greska" role="alert">{{ $greska }}</p>
        @endisset
        <button type="submit">{{ \App\Support\PrikazPomocnika::DUGME }}</button>
    </form>

    @include('javno.pomocnik-rezultat')
@endsection
