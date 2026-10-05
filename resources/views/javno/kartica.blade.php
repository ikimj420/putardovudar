<article class="kartica">
    <h2><a href="{{ route('prilike.show', $prilika->slug) }}">{{ $prilika->naslov }}</a></h2>
    <p><span class="oznaka">{{ $prilika->vrsta->getLabel() }}</span></p>
    <p class="red">{{ \App\Support\PrikazPrilike::rok($prilika) }}</p>
    @if (\App\Support\PrikazPrilike::mesto($prilika))
        <p class="red">{{ \App\Support\PrikazPrilike::mesto($prilika) }}</p>
    @endif
    <p class="kratko">{{ $prilika->kratak_opis }}</p>
</article>
