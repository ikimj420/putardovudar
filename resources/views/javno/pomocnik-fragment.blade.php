{{-- Odgovor za plutajući prozor: jedna poruka pomoćnika (pitanje je već u razgovoru). Bez velikih naslova; izvori su male kartice.
     Isto pitanje, ista pravila i ista provera kao na /pomocnik. --}}
@isset($greska)
    <div class="poruka poruka-pomocnik"><p class="greska" role="alert">{{ $greska }}</p></div>
@endisset

@isset($nijeDostupan)
    <div class="poruka poruka-pomocnik"><p class="prazno" role="status">{{ \App\Support\PrikazPomocnika::NE_RADI }}</p></div>
@endisset

@isset($odgovor)
    <div class="poruka poruka-pomocnik">
        <p>{!! nl2br(e($odgovor->tekst)) !!}</p>

        @if (count($odgovor->ponude) > 0)
            <div class="dugme-red">
                @foreach ($odgovor->ponude as $ponuda)
                    <a class="dugme-veza" href="{{ $ponuda->adresa }}" data-pomocnik-sablon>{{ \App\Support\PrikazPomocnika::NAPISI_PONUDA }}: {{ $ponuda->naslov }}</a>
                @endforeach
            </div>
        @endif

        @if (count($odgovor->izvori) > 0)
            <div class="izvori">
                @foreach ($odgovor->izvori as $izvor)
                    <article class="kartica izvor-mala">
                        <p class="izvor-naslov"><a class="kartica-veza" href="{{ $izvor->adresa }}">{{ $izvor->naslov }}</a></p>
                        @if ($izvor->jeVodic)
                            <p class="izvor-podatak">{{ \App\Support\PrikazVodica::OZNAKA }}</p>
                        @elseif ($izvor->jeOrganizacija)
                            <p class="izvor-podatak">{{ \App\Support\PrikazOrganizacija::OZNAKA }}</p>
                        @elseif ($izvor->jeKalendar)
                            <p class="izvor-podatak">{{ \App\Support\PrikazKalendara::OZNAKA }}: {{ $izvor->rok }}</p>
                        @else
                            <p class="izvor-podatak">{{ $izvor->rok }}</p>
                        @endif
                        @if ($izvor->zvanicniNaziv)
                            <p class="izvor-podatak">
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
    </div>
@endisset
