@extends('javno.raspored', ['naslov' => \App\Support\PrikazVodica::NASLOV])

@section('sadrzaj')
    <h1>{{ \App\Support\PrikazVodica::NASLOV }}</h1>

    @forelse ($vodici as $vodic)
        @if ($loop->first)
            <div class="spisak">
        @endif

        @include('javno.vodic-kartica', ['vodic' => $vodic])

        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="prazno">{{ \App\Support\PrikazVodica::NEMA_VODICA }}</p>
    @endforelse
@endsection
