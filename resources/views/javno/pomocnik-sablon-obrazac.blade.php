{{-- Napisan tekst (ako je već napisan) i pitanja šablona; isto na strani pomoćnika i u plutajućem prozoru. --}}
@isset($greska)
    <p class="greska" role="alert">{{ $greska }}</p>
@endisset

@isset($napisan)
    <section class="ploca sklopljen" aria-labelledby="tvoj-tekst">
        <h2 id="tvoj-tekst">{{ \App\Support\PrikazPomocnika::NASLOV_TEKSTA }}</h2>
        <p class="uputstvo">{{ $napisan->napisaoModel ? \App\Support\PrikazPomocnika::NAPOMENA_NAPISAN : \App\Support\PrikazPomocnika::NAPOMENA_SLOZEN }}</p>
        <div class="sklopljen-tekst" id="napisan-tekst">{{ $napisan->tekst }}</div>
        <button class="dugme-kopiraj" type="button" data-kopiraj="napisan-tekst" data-kopirano="{{ \App\Support\PrikazVodica::KOPIRANO }}" aria-live="polite" hidden>{{ \App\Support\PrikazVodica::KOPIRAJ }}</button>
    </section>
@endisset

<form class="obrazac" method="post" action="{{ route('pomocnik.napisi', $sablon->slug) }}" data-pomocnik-sablon-forma>
    @csrf
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
