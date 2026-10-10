@extends('javno.raspored', ['naslov' => \App\Support\PrikazOrganizacija::NASLOV])

@section('sadrzaj')
    <h1>{{ \App\Support\PrikazOrganizacija::NASLOV }}</h1>

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
