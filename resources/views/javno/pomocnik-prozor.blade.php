{{-- Plutajući pomoćnik na svakoj javnoj strani osim na samoj strani pomoćnika. Bez skripta dugme je obična veza ka strani pomoćnika, a prozor ostaje skriven.
     Prozor je razgovor: pitanje čovek vidi kao svoju poruku, a odgovor stiže ispod nje; Enter šalje, Shift+Enter pravi nov red. --}}
<aside class="plutajuci" aria-label="{{ \App\Support\PrikazPomocnika::NASLOV }}" data-pomocnik>
    <a class="plutajuci-dugme" href="{{ route('pomocnik.index') }}" data-pomocnik-otvori aria-controls="pomocnik-prozor"><span class="plutajuci-krug" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 64 64" focusable="false"><path d="M12 60V28C12 15 21 6 32 6s20 9 20 22v32" fill="none" stroke="currentColor" stroke-width="6"/><path d="M32 14l12 6v40H32z" fill="currentColor"/></svg></span>{{ \App\Support\PrikazPomocnika::PITAJ_VUDAR }}</a>
    <section class="plutajuci-prozor" id="pomocnik-prozor" role="dialog" aria-labelledby="pomocnik-naslov" data-trazim="{{ \App\Support\PrikazPomocnika::TRAZIM }}" hidden>
        <div class="plutajuci-glava">
            <h2 id="pomocnik-naslov">{{ \App\Support\PrikazPomocnika::NASLOV }}</h2>
            <button class="plutajuci-zatvori" type="button" data-pomocnik-zatvori>{{ \App\Support\PrikazPomocnika::ZATVORI }}</button>
        </div>
        <div class="plutajuci-telo">
            <div class="plutajuci-rezultat razgovor" data-pomocnik-rezultat tabindex="-1" role="log" aria-live="polite">
                <div class="poruka poruka-pomocnik"><p>{{ \App\Support\PrikazPomocnika::UVOD }}</p></div>
            </div>
        </div>
        <form class="pitanje plutajuci-forma" method="post" action="{{ route('pomocnik.pitaj') }}" data-pomocnik-forma>
            @csrf
            <label class="samo-citac" for="pomocnik-pitanje">{{ \App\Support\PrikazPomocnika::OZNAKA_POLJA }}</label>
            <textarea id="pomocnik-pitanje" name="pitanje" rows="1" maxlength="{{ \App\Services\Pomocnik\Pomocnik::NAJVISE_ZNAKOVA_PITANJA }}" required placeholder="{{ \App\Support\PrikazPomocnika::PRIMER }}"></textarea>
            <button type="submit">{{ \App\Support\PrikazPomocnika::DUGME }}</button>
            @include('javno.pomocnik-natpis')
        </form>
    </section>
</aside>
