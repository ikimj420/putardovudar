<article class="kartica redosled">
    <h2><a class="kartica-veza" href="{{ route('vodici.show', $vodic->slug) }}">{{ $vodic->naslov }}</a></h2>
    <p class="kratko">{{ $vodic->kratak_opis }}</p>
</article>
