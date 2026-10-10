@extends('javno.raspored', ['naslov' => \App\Support\PrikazVodica::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazVodica::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazVodica::NASLOV }}</h1>
@endsection

@section('sadrzaj')
    @forelse ($vodici as $vodic)
        @if ($loop->first)
            <div class="spisak tri">
        @endif

        @include('javno.vodic-kartica', ['vodic' => $vodic])

        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="prazno">{{ \App\Support\PrikazVodica::NEMA_VODICA }}</p>
    @endforelse
@endsection
