<article class="kartica">
    <h2><a href="{{ route('vodici.show', $vodic->slug) }}">{{ $vodic->naslov }}</a></h2>
    <p class="kratko">{{ $vodic->kratak_opis }}</p>
</article>
