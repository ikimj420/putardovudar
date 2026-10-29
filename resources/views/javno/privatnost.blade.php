@extends('javno.raspored', ['naslov' => \App\Support\PrikazPrivatnosti::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazPrivatnosti::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazPrivatnosti::NASLOV }}</h1>
    <p class="uvod">{{ \App\Support\PrikazPrivatnosti::UVOD }}</p>
@endsection

@section('sadrzaj')
    <section class="ploca o-privatnosti">
        @foreach (\App\Support\PrikazPrivatnosti::PASUSI as $pasus)
            <p>{{ $pasus }}</p>
        @endforeach
    </section>
@endsection
