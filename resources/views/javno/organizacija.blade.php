@extends('javno.raspored', ['naslov' => $organizacija->naziv])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazOrganizacija::NASLOV, route('organizacije.index')], [$organizacija->naziv, null]]])
    <h1>{{ $organizacija->naziv }}</h1>
    <p class="kratko">{{ $organizacija->kratak_opis }}</p>
    <div class="oznake">
        <span class="oznaka">{{ $organizacija->vrsta->getLabel() }}</span>
        @if ($organizacija->online)
            <span class="meta-stavka">{{ \App\Support\PrikazOrganizacija::ONLINE }}</span>
        @endif
        @if (filled($organizacija->mesto))
            <span class="meta-stavka">{{ \App\Support\PrikazOrganizacija::MESTO }}: {{ $organizacija->mesto }}</span>
        @endif
        @if (filled($organizacija->telefon))
            <span class="meta-stavka">{{ \App\Support\PrikazOrganizacija::TELEFON }}: {{ $organizacija->telefon }}</span>
        @endif
    </div>
@endsection

@section('sadrzaj')
    <div class="{{ \App\Support\PrikazOrganizacija::linkSajta($organizacija) ? 'detalj sa-bocnim' : 'detalj' }}">
        <article class="prilika detalj">
            @if (filled($organizacija->opis))
                <div class="ploca">
                    <div class="sadrzaj-opis">{!! nl2br(e($organizacija->opis)) !!}</div>
                </div>
            @endif

            @if (count($organizacija->usluge ?? []) > 0)
                <div class="ploca">
                    <h2 class="naslov-izvora">{{ \App\Support\PrikazOrganizacija::USLUGE }}</h2>
                    <ul class="usluge">
                        @foreach ($organizacija->usluge as $usluga)
                            <li>{{ $usluga }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </article>

        @if (\App\Support\PrikazOrganizacija::linkSajta($organizacija))
            <div class="izvor">
                <p>
                    {{ \App\Support\PrikazOrganizacija::SAJT }}:
                    <a href="{{ \App\Support\PrikazOrganizacija::linkSajta($organizacija) }}" rel="noopener noreferrer nofollow" target="_blank">{{ \App\Support\PrikazOrganizacija::nazivSajta(\App\Support\PrikazOrganizacija::linkSajta($organizacija)) }}</a>
                </p>
            </div>
        @endif
    </div>
@endsection
