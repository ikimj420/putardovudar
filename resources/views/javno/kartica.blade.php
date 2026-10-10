<article class="kartica redosled">
    <h2><a class="kartica-veza" href="{{ route('prilike.show', $prilika->slug) }}">{{ $prilika->naslov }}</a></h2>
    <div class="oznake">
        <span class="oznaka" data-vrsta="{{ $prilika->vrsta->value }}">{{ $prilika->vrsta->getLabel() }}</span>
        @if (\App\Support\PrikazPrilike::zatvaraSe($prilika))
            <span class="oznaka oznaka-rok">{{ \App\Support\PrikazPrilike::zatvaraSe($prilika) }}</span>
        @endif
    </div>
    <div class="meta">
        <span class="meta-stavka">{{ \App\Support\PrikazPrilike::rok($prilika) }}</span>
        @if (\App\Support\PrikazPrilike::mesto($prilika))
            <span class="meta-stavka">{{ \App\Support\PrikazPrilike::mesto($prilika) }}</span>
        @endif
    </div>
    <p class="kratko">{{ $prilika->kratak_opis }}</p>
</article>
