@extends('javno.raspored', ['naslov' => \App\Support\PrikazPomocnika::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazPomocnika::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazPomocnika::NASLOV }}</h1>
    <p class="uvod">{{ \App\Support\PrikazPomocnika::UVOD }}</p>
@endsection

@section('sadrzaj')
    <form class="pitanje" method="post" action="{{ route('pomocnik.pitaj') }}">
        @csrf
        <label for="pitanje">{{ \App\Support\PrikazPomocnika::OZNAKA_POLJA }}</label>
        <textarea id="pitanje" name="pitanje" rows="3" maxlength="{{ \App\Services\Pomocnik\Pomocnik::NAJVISE_ZNAKOVA_PITANJA }}" required placeholder="{{ \App\Support\PrikazPomocnika::PRIMER }}">{{ $pitanje ?? '' }}</textarea>
        @isset($greska)
            <p class="greska" role="alert">{{ $greska }}</p>
        @endisset
        <button type="submit">{{ \App\Support\PrikazPomocnika::DUGME }}</button>
    </form>

    @isset($nijeDostupan)
        <p class="prazno" role="status">{{ \App\Support\PrikazPomocnika::NE_RADI }}</p>
    @endisset

    @isset($odgovor)
        <section class="kartica odgovor">
            <h2>{{ \App\Support\PrikazPomocnika::NASLOV_ODGOVORA }}</h2>
            <p>{!! nl2br(e($odgovor->tekst)) !!}</p>
        </section>

        @if (count($odgovor->izvori) > 0)
            <h2 class="naslov-izvora">{{ \App\Support\PrikazPomocnika::NASLOV_IZVORA }}</h2>
            <div class="spisak">
                @foreach ($odgovor->izvori as $izvor)
                    <article class="kartica">
                        <h3><a href="{{ $izvor->adresa }}">{{ $izvor->naslov }}</a></h3>
                        @if ($izvor->jeVodic)
                            <p><span class="oznaka">{{ \App\Support\PrikazVodica::OZNAKA }}</span></p>
                        @elseif ($izvor->jeOrganizacija)
                            <p><span class="oznaka">{{ \App\Support\PrikazOrganizacija::OZNAKA }}</span></p>
                        @else
                            <p class="red">{{ $izvor->rok }}</p>
                        @endif
                        @if ($izvor->zvanicniNaziv)
                            <p class="red">
                                {{ $izvor->jeOrganizacija ? \App\Support\PrikazOrganizacija::SAJT.': ' : \App\Support\PrikazPrilike::ZVANICNI_IZVOR }}
                                @if ($izvor->zvanicniLink)
                                    <a href="{{ $izvor->zvanicniLink }}" rel="noopener noreferrer nofollow" target="_blank">{{ $izvor->zvanicniNaziv }}</a>
                                @else
                                    {{ $izvor->zvanicniNaziv }}
                                @endif
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
            <p class="izvor-upozorenje">{{ \App\Support\PrikazPrilike::PROVERI_IZVOR }}</p>
        @endif
    @endisset
@endsection
