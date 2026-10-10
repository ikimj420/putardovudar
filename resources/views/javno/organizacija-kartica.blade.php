<article class="kartica redosled">
    <h2><a href="{{ route('organizacije.show', $organizacija->slug) }}">{{ $organizacija->naziv }}</a></h2>
    <div class="oznake"><span class="oznaka">{{ $organizacija->vrsta->getLabel() }}</span></div>
    @if (filled($organizacija->mesto))
        <div class="meta"><span class="meta-stavka">{{ $organizacija->mesto }}</span></div>
    @endif
    <p class="kratko">{{ $organizacija->kratak_opis }}</p>
</article>
