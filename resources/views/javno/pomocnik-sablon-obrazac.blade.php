{{-- Napisan tekst (ako je već napisan) i pitanja šablona; isto na strani pomoćnika i u plutajućem prozoru. --}}
@isset($greska)
    <p class="greska" role="alert">{{ $greska }}</p>
@endisset

@isset($pitanjeRoda)
    <section class="ploca rod-pitanje" aria-labelledby="rod-pitanje">
        <h2 id="rod-pitanje">{{ \App\Support\PrikazPomocnika::ROD_PITANJE }}</h2>
        @include('javno.pomocnik-sablon-rod', ['izbori' => \App\Support\PrikazPomocnika::ROD_IZBOR])
    </section>
@endisset

@isset($napisan)
    <section class="ploca sklopljen" aria-labelledby="tvoj-tekst">
        <h2 id="tvoj-tekst">{{ \App\Support\PrikazPomocnika::NASLOV_TEKSTA }}</h2>
        <p class="uputstvo">{{ $napisan->napisaoModel ? \App\Support\PrikazPomocnika::NAPOMENA_NAPISAN : \App\Support\PrikazPomocnika::NAPOMENA_SLOZEN }}</p>
        <div class="sklopljen-tekst" id="napisan-tekst">{{ $napisan->tekst }}</div>
        <button class="dugme-kopiraj" type="button" data-kopiraj="napisan-tekst" data-kopirano="{{ \App\Support\PrikazVodica::KOPIRANO }}" aria-live="polite" hidden>{{ \App\Support\PrikazVodica::KOPIRAJ }}</button>
        {{-- Link za promenu roda ima smisla samo kad bi se tekst promenio: piše ga model, ili šablon ima mesta sa muškim i ženskim oblikom. --}}
        @if (($rod ?? null) !== null && ($napisan->napisaoModel || $sablon->imaRod()))
            <div class="uputstvo">
                @include('javno.pomocnik-sablon-rod', ['izbori' => [$rod->suprotan()->value => \App\Support\PrikazPomocnika::PROMENI_ROD[$rod->suprotan()->value]], 'veza' => true])
            </div>
        @endif
    </section>
@endisset

<form class="obrazac" method="post" action="{{ route('pomocnik.napisi', $sablon->slug) }}" data-pomocnik-sablon-forma>
    @csrf
    @if (($izabranRod ?? null) !== null)
        <input type="hidden" name="izabran_rod" value="{{ $izabranRod->value }}">
    @endif
    @foreach ($sablon->polja as $polje)
        <div class="polje">
            <label for="polje-{{ $polje['kljuc'] }}">{{ $polje['oznaka'] }}</label>
            @if (filled($polje['objasnjenje'] ?? null))
                <p class="polje-objasnjenje" id="objasnjenje-{{ $polje['kljuc'] }}">{{ $polje['objasnjenje'] }}</p>
            @endif
            @if ($polje['dugo'] ?? false)
                <textarea id="polje-{{ $polje['kljuc'] }}" name="polje[{{ $polje['kljuc'] }}]" rows="5" maxlength="{{ \App\Support\SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA }}"@if (filled($polje['objasnjenje'] ?? null)) aria-describedby="objasnjenje-{{ $polje['kljuc'] }}"@endif>{{ $unosi[$polje['kljuc']] ?? '' }}</textarea>
            @else
                <input id="polje-{{ $polje['kljuc'] }}" type="text" name="polje[{{ $polje['kljuc'] }}]" maxlength="255" value="{{ $unosi[$polje['kljuc']] ?? '' }}"@if (filled($polje['objasnjenje'] ?? null)) aria-describedby="objasnjenje-{{ $polje['kljuc'] }}"@endif>
            @endif
        </div>
    @endforeach
    <button type="submit">{{ \App\Support\PrikazPomocnika::NAPISI }}</button>
</form>
