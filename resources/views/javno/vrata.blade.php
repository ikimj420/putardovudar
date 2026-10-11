{{-- Otvorena vrata: luk rama, svetlo u otvoru, krilo otvoreno ka unutra koje prati luk i svetlo na podu centrirano ispod vrata. U svetlu su pravi linkovi ka vrstama koje imaju javnu priliku; slika je samo ukras. --}}
<div class="vrata-okvir">
    <svg class="vrata" viewBox="0 0 220 270" aria-hidden="true" focusable="false">
        <defs>
            <radialGradient id="vrata-oreol" cx=".5" cy=".5" r=".5"><stop class="vrata-oreol" offset="0" stop-opacity=".5"/><stop class="vrata-oreol" offset=".6" stop-opacity=".14"/><stop class="vrata-oreol" offset="1" stop-opacity="0"/></radialGradient>
            <linearGradient id="vrata-unutra" x1="0" y1="0" x2="0" y2="1"><stop class="vrata-svetlo-1" offset="0"/><stop class="vrata-svetlo-2" offset=".55"/><stop class="vrata-svetlo-3" offset="1"/></linearGradient>
            <linearGradient id="vrata-krilo" x1="0" y1="0" x2="1" y2="0"><stop class="vrata-krilo-1" offset="0"/><stop class="vrata-krilo-2" offset="1"/></linearGradient>
            <radialGradient id="vrata-pod" cx=".5" cy=".5" r=".5"><stop class="vrata-pod" offset="0" stop-opacity=".8"/><stop class="vrata-pod" offset="1" stop-opacity="0"/></radialGradient>
                    </defs>
        <circle cx="110" cy="140" r="110" fill="url(#vrata-oreol)"/>
        <ellipse cx="110" cy="246" rx="92" ry="16" fill="url(#vrata-pod)"/>
        <path d="M52 242V118a58 58 0 0 1 116 0v124z" fill="url(#vrata-unutra)"/>
        <path d="M138 67.2A58 58 0 0 1 168 118v124h-30z" fill="url(#vrata-krilo)"/>
        <path d="M138 67.2V242" stroke="#fff" stroke-opacity=".55" stroke-width="1.2"/>
        <path d="M40 242V116a70 70 0 0 1 140 0v126" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="5" stroke-linecap="round"/>
    </svg>
    @if ($vrste !== [])
        <nav class="u-svetlu" aria-label="{{ \App\Support\PrikazPrilike::VRSTE_PRILIKA }}">
            @foreach ($vrste as $vrsta)
                <a class="vrsta-veza" href="{{ \App\Support\PrikazPrilike::adresaSpiska($vrsta) }}"><span class="vrsta-tacka" data-vrsta="{{ $vrsta->value }}"></span>{{ $vrsta->getLabel() }}</a>
            @endforeach
        </nav>
    @endif
</div>
