@extends('javno.raspored', ['naslov' => $sablon->naslov])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazPomocnika::NASLOV, route('pomocnik.index')], [$sablon->naslov, null]]])
    <h1>{{ $sablon->naslov }}</h1>
    <p class="kratko">{{ $sablon->objasnjenje }}</p>
@endsection

@section('sadrzaj')
    @include('javno.pomocnik-sablon-obrazac')
@endsection
