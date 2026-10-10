@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], ['Prilike', null]]])
    <h1>Prilike</h1>
    @include('javno.pretraga', ['pretraga' => $pretraga, 'izabrana' => $izabrana, 'uskoro' => $uskoro])
@endsection

@section('sadrzaj')
    <nav class="filteri" aria-label="{{ \App\Support\PrikazPrilike::FILTERI }}">
        {{-- „Sve prilike" briše vrstu i „Zatvara se uskoro"; vrste čuvaju „uskoro", a „uskoro" čuva vrstu i pretragu (isključuje se istim izborom). --}}
        <a class="filter-pilula{{ $izabrana === null && ! $uskoro ? ' aktivno' : '' }}" href="{{ \App\Support\PrikazPrilike::adresaSpiska(null, $pretraga) }}"@if ($izabrana === null && ! $uskoro) aria-current="true"@endif>{{ \App\Support\PrikazPrilike::SVE_PRILIKE }}</a>
        @foreach ($vrste as $vrsta)
            <a class="filter-pilula{{ $izabrana === $vrsta ? ' aktivno' : '' }}" href="{{ \App\Support\PrikazPrilike::adresaSpiska($vrsta, $pretraga, $uskoro) }}"@if ($izabrana === $vrsta) aria-current="true"@endif>{{ $vrsta->getLabel() }}</a>
        @endforeach
        @if ($imaUskoro)
            <a class="filter-pilula{{ $uskoro ? ' aktivno' : '' }}" href="{{ \App\Support\PrikazPrilike::adresaSpiska($izabrana, $pretraga, ! $uskoro) }}"@if ($uskoro) aria-current="true"@endif>{{ \App\Support\PrikazPrilike::USKORO }}</a>
        @endif
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
        <p class="prazno">{{ $pretraga !== '' ? \App\Support\PrikazPrilike::NEMA_REZULTATA : ($uskoro ? \App\Support\PrikazPrilike::NEMA_USKORO : \App\Support\PrikazPrilike::NEMA_PRILIKA) }}</p>
    @endforelse
@endsection
