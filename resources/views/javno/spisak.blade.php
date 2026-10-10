@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], ['Prilike', null]]])
    <h1>Prilike</h1>
    @include('javno.pretraga', ['pretraga' => $pretraga, 'izabrana' => $izabrana])
@endsection

@section('sadrzaj')
    <nav class="filteri" aria-label="{{ \App\Support\PrikazPrilike::FILTERI }}">
        <a class="filter-pilula{{ $izabrana === null ? ' aktivno' : '' }}" href="{{ \App\Support\PrikazPrilike::adresaSpiska(null, $pretraga) }}"@if ($izabrana === null) aria-current="true"@endif>{{ \App\Support\PrikazPrilike::SVE_PRILIKE }}</a>
        @foreach ($vrste as $vrsta)
            <a class="filter-pilula{{ $izabrana === $vrsta ? ' aktivno' : '' }}" href="{{ \App\Support\PrikazPrilike::adresaSpiska($vrsta, $pretraga) }}"@if ($izabrana === $vrsta) aria-current="true"@endif>{{ $vrsta->getLabel() }}</a>
        @endforeach
    </nav>

    @forelse ($prilike as $prilika)
        @if ($loop->first)
            <div class="spisak tri">
        @endif

        @include('javno.kartica', ['prilika' => $prilika])

        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="prazno">{{ $pretraga !== '' ? \App\Support\PrikazPrilike::NEMA_REZULTATA : \App\Support\PrikazPrilike::NEMA_PRILIKA }}</p>
    @endforelse
@endsection
