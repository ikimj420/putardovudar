<article class="kartica redosled">
    <h2><a class="kartica-veza" href="{{ route('sabloni.show', $sablon->slug) }}">{{ $sablon->naslov }}</a></h2>
    <div class="oznake"><span class="oznaka">{{ \App\Support\PrikazSablona::OZNAKA }}</span></div>
    <p class="kratko">{{ $sablon->objasnjenje }}</p>
</article>
