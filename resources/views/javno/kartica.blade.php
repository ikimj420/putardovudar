<article>
    <h2>{{ $prilika->naslov }}</h2>
    <p>{{ $prilika->vrsta->getLabel() }}</p>
    <p>{{ \App\Support\PrikazPrilike::rok($prilika) }}</p>
    @if (\App\Support\PrikazPrilike::mesto($prilika))
        <p>{{ \App\Support\PrikazPrilike::mesto($prilika) }}</p>
    @endif
    <p>{{ $prilika->kratak_opis }}</p>
</article>
