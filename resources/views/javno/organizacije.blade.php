@extends('javno.raspored', ['naslov' => \App\Support\PrikazOrganizacija::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazOrganizacija::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazOrganizacija::NASLOV }}</h1>
@endsection

@section('sadrzaj')
    @forelse ($organizacije as $organizacija)
        @if ($loop->first)
            <div class="spisak">
        @endif

        @include('javno.organizacija-kartica', ['organizacija' => $organizacija])

        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="prazno">{{ \App\Support\PrikazOrganizacija::NEMA_ORGANIZACIJA }}</p>
    @endforelse
@endsection
