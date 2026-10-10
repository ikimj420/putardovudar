@extends('javno.raspored', ['naslov' => $organizacija->naziv])

@section('sadrzaj')
    <article class="prilika">
        <h1>{{ $organizacija->naziv }}</h1>
        <p><span class="oznaka">{{ $organizacija->vrsta->getLabel() }}</span></p>
        <p class="kratko">{{ $organizacija->kratak_opis }}</p>
        @if (filled($organizacija->opis))
            <div class="sadrzaj-opis">{!! nl2br(e($organizacija->opis)) !!}</div>
        @endif

        @if (filled($organizacija->mesto))
            <p class="red">{{ \App\Support\PrikazOrganizacija::MESTO }}: {{ $organizacija->mesto }}</p>
        @endif
        @if ($organizacija->online)
            <p class="red">{{ \App\Support\PrikazOrganizacija::ONLINE }}</p>
        @endif
        @if (filled($organizacija->telefon))
            <p class="red">{{ \App\Support\PrikazOrganizacija::TELEFON }}: {{ $organizacija->telefon }}</p>
        @endif
        @if (\App\Support\PrikazOrganizacija::linkSajta($organizacija))
            <div class="izvor">
                <p>
                    {{ \App\Support\PrikazOrganizacija::SAJT }}:
                    <a href="{{ \App\Support\PrikazOrganizacija::linkSajta($organizacija) }}" rel="noopener noreferrer nofollow" target="_blank">{{ \App\Support\PrikazOrganizacija::nazivSajta(\App\Support\PrikazOrganizacija::linkSajta($organizacija)) }}</a>
                </p>
            </div>
        @endif

        @if (count($organizacija->usluge ?? []) > 0)
            <h2 class="naslov-izvora">{{ \App\Support\PrikazOrganizacija::USLUGE }}</h2>
            <ul class="usluge">
                @foreach ($organizacija->usluge as $usluga)
                    <li>{{ $usluga }}</li>
                @endforeach
            </ul>
        @endif
    </article>
@endsection
