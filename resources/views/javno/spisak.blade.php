@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('sadrzaj')
    <h1>Prilike</h1>

    @forelse ($prilike as $prilika)
        @include('javno.kartica', ['prilika' => $prilika])
    @empty
        <p>{{ \App\Support\PrikazPrilike::NEMA_PRILIKA }}</p>
    @endforelse
@endsection
