@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('sadrzaj')
    <h1>Prilike</h1>
    <p class="pomocnik-link">{{ \App\Support\PrikazPomocnika::UVOD_LINKA }} <a href="{{ route('pomocnik.index') }}">{{ \App\Support\PrikazPomocnika::LINK_SA_POCETNE }}</a></p>

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
