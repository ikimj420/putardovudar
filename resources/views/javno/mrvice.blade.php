{{-- Putanja do strane: ime sajta, odeljak i naslov; svaki deo je [tekst, adresa ili null]. --}}
<nav class="mrvice" aria-label="{{ \App\Support\PrikazZaglavlja::PUTANJA }}">
    @foreach ($delovi as [$tekst, $adresa])
        @if (! $loop->first)
            <span aria-hidden="true">/</span>
        @endif
        @if ($adresa)
            <a href="{{ $adresa }}">{{ $tekst }}</a>
        @else
            <span>{{ $tekst }}</span>
        @endif
    @endforeach
</nav>
