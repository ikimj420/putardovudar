{{-- Plutajući pomoćnik na svakoj javnoj strani osim na samoj strani pomoćnika. Bez skripta dugme je obična veza ka strani pomoćnika, a prozor ostaje skriven. --}}
<div class="plutajuci" data-pomocnik>
    <a class="plutajuci-dugme" href="{{ route('pomocnik.index') }}" data-pomocnik-otvori aria-controls="pomocnik-prozor">{{ \App\Support\PrikazPomocnika::NASLOV }}</a>
    <section class="plutajuci-prozor" id="pomocnik-prozor" role="dialog" aria-labelledby="pomocnik-naslov" data-trazim="{{ \App\Support\PrikazPomocnika::TRAZIM }}" hidden>
        <div class="plutajuci-glava">
            <h2 id="pomocnik-naslov">{{ \App\Support\PrikazPomocnika::NASLOV }}</h2>
            <button class="plutajuci-zatvori" type="button" data-pomocnik-zatvori>{{ \App\Support\PrikazPomocnika::ZATVORI }}</button>
        </div>
        <div class="plutajuci-telo">
            <p class="plutajuci-uvod">{{ \App\Support\PrikazPomocnika::UVOD }}</p>
            <div class="plutajuci-rezultat" data-pomocnik-rezultat tabindex="-1" aria-live="polite"></div>
        </div>
        <form class="pitanje plutajuci-forma" method="post" action="{{ route('pomocnik.pitaj') }}" data-pomocnik-forma>
            @csrf
            <label for="pomocnik-pitanje">{{ \App\Support\PrikazPomocnika::OZNAKA_POLJA }}</label>
            <textarea id="pomocnik-pitanje" name="pitanje" rows="2" maxlength="{{ \App\Services\Pomocnik\Pomocnik::NAJVISE_ZNAKOVA_PITANJA }}" required placeholder="{{ \App\Support\PrikazPomocnika::PRIMER }}"></textarea>
            <button type="submit">{{ \App\Support\PrikazPomocnika::DUGME }}</button>
        </form>
    </section>
</div>
