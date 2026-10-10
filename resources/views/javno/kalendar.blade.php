@extends('javno.raspored', ['naslov' => \App\Support\PrikazKalendara::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazKalendara::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazKalendara::NASLOV }}</h1>
    <p class="uvod">{{ \App\Support\PrikazKalendara::UVOD }}</p>
@endsection

@section('sadrzaj')
    @forelse ($meseci as $mesec)
        <h2 class="naslov-izvora">{{ $mesec['naziv'] }}</h2>
        <div class="spisak">
            @foreach ($mesec['stavke'] as $red)
                @php($stavka = $red['stavka'])
                <article class="kartica">
                    <h3>{{ $stavka->naziv }}</h3>
                    <div class="meta">
                        <span class="meta-stavka">{{ \App\Support\PrikazKalendara::prosliPut($stavka) }}</span>
                        @if (filled($stavka->ko_daje))
                            <span class="meta-stavka">{{ $stavka->ko_daje }}</span>
                        @endif
                        @if (\App\Support\PrikazPrilike::nazivi($stavka->izabraneGrupe()))
                            <span class="meta-stavka grupe">{{ \App\Support\PrikazPrilike::KOME }}: {{ \App\Support\PrikazPrilike::nazivi($stavka->izabraneGrupe()) }}</span>
                        @endif
                    </div>
                    @if (filled($stavka->napomena))
                        <p class="kratko">{{ $stavka->napomena }}</p>
                    @endif
                    @if (\App\Support\LinkSajta::jeIspravan($stavka->link_izvora))
                        <p class="red">
                            {{ \App\Support\PrikazPrilike::ZVANICNI_IZVOR }}
                            <a href="{{ trim($stavka->link_izvora) }}" rel="noopener noreferrer nofollow" target="_blank">{{ \App\Support\PrikazOrganizacija::nazivSajta(trim($stavka->link_izvora)) }}</a>
                        </p>
                    @endif
                    @if ($red['prilika'])
                        <p class="red">
                            {{ \App\Support\PrikazKalendara::SADA_OTVORENO }}
                            <a href="{{ route('prilike.show', $red['prilika']->slug) }}">{{ $red['prilika']->naslov }}</a>
                        </p>
                    @endif
                </article>
            @endforeach
        </div>
    @empty
        <p class="prazno">{{ \App\Support\PrikazKalendara::NEMA_STAVKI }}</p>
    @endforelse
@endsection
