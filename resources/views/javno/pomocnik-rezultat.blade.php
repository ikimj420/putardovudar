@isset($nijeDostupan)
    <p class="prazno" role="status">{{ \App\Support\PrikazPomocnika::NE_RADI }}</p>
@endisset

@isset($odgovor)
    <section class="kartica odgovor">
        <h2>{{ \App\Support\PrikazPomocnika::NASLOV_ODGOVORA }}</h2>
        <p>{!! nl2br(e($odgovor->tekst)) !!}</p>
    </section>

    @if (count($odgovor->ponude) > 0)
        <div class="dugme-red">
            @foreach ($odgovor->ponude as $ponuda)
                <a class="dugme-veza" href="{{ $ponuda->adresa }}" data-pomocnik-sablon>{{ \App\Support\PrikazPomocnika::NAPISI_PONUDA }}: {{ $ponuda->naslov }}</a>
            @endforeach
        </div>
    @endif

    @if (count($odgovor->izvori) > 0)
        <h2 class="naslov-izvora">{{ \App\Support\PrikazPomocnika::NASLOV_IZVORA }}</h2>
        <div class="spisak">
            @foreach ($odgovor->izvori as $izvor)
                <article class="kartica">
                    <h3><a class="kartica-veza" href="{{ $izvor->adresa }}">{{ $izvor->naslov }}</a></h3>
                    @if ($izvor->jeVodic)
                        <p><span class="oznaka">{{ \App\Support\PrikazVodica::OZNAKA }}</span></p>
                    @elseif ($izvor->jeOrganizacija)
                        <p><span class="oznaka">{{ \App\Support\PrikazOrganizacija::OZNAKA }}</span></p>
                    @elseif ($izvor->jeKalendar)
                        <p><span class="oznaka">{{ \App\Support\PrikazKalendara::OZNAKA }}</span></p>
                        <p class="red">{{ $izvor->rok }}</p>
                    @else
                        <p class="red">{{ $izvor->rok }}</p>
                    @endif
                    @if ($izvor->zvanicniNaziv)
                        <p class="red red-izvora">
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
