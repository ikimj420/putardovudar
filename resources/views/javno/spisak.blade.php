@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], ['Prilike', null]]])
    <h1>Prilike</h1>
@endsection

@section('sadrzaj')
    <nav class="filteri" aria-label="{{ \App\Support\PrikazPrilike::FILTERI }}">
        <a class="filter-pilula{{ $izabrana === null ? ' aktivno' : '' }}" href="{{ route('prilike.index') }}"@if ($izabrana === null) aria-current="true"@endif>{{ \App\Support\PrikazPrilike::SVE_PRILIKE }}</a>
        @foreach ($vrste as $vrsta)
            <a class="filter-pilula{{ $izabrana === $vrsta ? ' aktivno' : '' }}" href="{{ route('prilike.index', ['vrsta' => $vrsta->value]) }}"@if ($izabrana === $vrsta) aria-current="true"@endif>{{ $vrsta->getLabel() }}</a>
        @endforeach
    </nav>

    @forelse ($prilike as $prilika)
        @if ($loop->first)
            <div class="spisak">
        @endif

        @include('javno.kartica', ['prilika' => $prilika])

        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="prazno">{{ \App\Support\PrikazPrilike::NEMA_PRILIKA }}</p>
    @endforelse
@endsection
