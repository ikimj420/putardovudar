@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('sadrzaj')
    <h1>Prilike</h1>

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
