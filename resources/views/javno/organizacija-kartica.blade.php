<article class="kartica">
    <h2><a href="{{ route('organizacije.show', $organizacija->slug) }}">{{ $organizacija->naziv }}</a></h2>
    <p><span class="oznaka">{{ $organizacija->vrsta->getLabel() }}</span></p>
    @if (filled($organizacija->mesto))
        <p class="red">{{ $organizacija->mesto }}</p>
    @endif
    <p class="kratko">{{ $organizacija->kratak_opis }}</p>
</article>
