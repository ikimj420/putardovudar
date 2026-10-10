@extends('javno.raspored', ['naslov' => \App\Support\PrikazSablona::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazSablona::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazSablona::NASLOV }}</h1>
    <p class="uvod">{{ \App\Support\PrikazSablona::UVOD }}</p>
@endsection

@section('sadrzaj')
    @forelse ($sabloni as $sablon)
        @if ($loop->first)
            <div class="spisak tri">
        @endif

        @include('javno.sablon-kartica', ['sablon' => $sablon])

        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="prazno">{{ \App\Support\PrikazSablona::NEMA_SABLONA }}</p>
    @endforelse
@endsection
