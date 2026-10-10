@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], ['Prilike', null]]])
    <h1>Prilike</h1>
@endsection

@section('sadrzaj')
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
