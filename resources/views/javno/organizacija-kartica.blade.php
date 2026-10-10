<article class="kartica redosled">
    <h2><a class="kartica-veza" href="{{ route('organizacije.show', $organizacija->slug) }}">{{ $organizacija->naziv }}</a></h2>
    <div class="oznake"><span class="oznaka">{{ $organizacija->vrsta->getLabel() }}</span></div>
    @if (filled($organizacija->mesto))
        <div class="meta"><span class="meta-stavka">{{ $organizacija->mesto }}</span></div>
    @endif
    <p class="kratko">{{ $organizacija->kratak_opis }}</p>
    @if (\App\Support\PrikazOrganizacija::brojZaPoziv($organizacija))
        <a class="dugme-veza" href="tel:{{ \App\Support\PrikazOrganizacija::brojZaPoziv($organizacija) }}" aria-label="{{ \App\Support\PrikazOrganizacija::POZOVI }}: {{ $organizacija->naziv }}">{{ \App\Support\PrikazOrganizacija::POZOVI }}</a>
    @endif
</article>
