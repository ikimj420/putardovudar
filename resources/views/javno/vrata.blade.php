{{-- Otvorena vrata: luk rama, svetlo u otvoru, krilo otvoreno ka unutra koje prati luk i svetlo na podu centrirano ispod vrata; u svetlu su oznake vrsta koje imaju javnu priliku. --}}
<div class="vrata-okvir">
    <svg class="vrata" viewBox="0 0 220 270" aria-hidden="true" focusable="false">
        <defs>
            <radialGradient id="vrata-oreol" cx=".5" cy=".5" r=".5"><stop class="vrata-oreol" offset="0" stop-opacity=".5"/><stop class="vrata-oreol" offset=".6" stop-opacity=".14"/><stop class="vrata-oreol" offset="1" stop-opacity="0"/></radialGradient>
            <linearGradient id="vrata-unutra" x1="0" y1="0" x2="0" y2="1"><stop class="vrata-svetlo-1" offset="0"/><stop class="vrata-svetlo-2" offset=".55"/><stop class="vrata-svetlo-3" offset="1"/></linearGradient>
            <linearGradient id="vrata-krilo" x1="0" y1="0" x2="1" y2="0"><stop class="vrata-krilo-1" offset="0"/><stop class="vrata-krilo-2" offset="1"/></linearGradient>
            <radialGradient id="vrata-pod" cx=".5" cy=".5" r=".5"><stop class="vrata-pod" offset="0" stop-opacity=".8"/><stop class="vrata-pod" offset="1" stop-opacity="0"/></radialGradient>
            <filter id="vrata-senka" x="-20%" y="-40%" width="140%" height="190%"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#270f43" flood-opacity=".25"/></filter>
        </defs>
        <circle cx="110" cy="140" r="110" fill="url(#vrata-oreol)"/>
        <ellipse cx="110" cy="246" rx="92" ry="16" fill="url(#vrata-pod)"/>
        <path d="M52 242V118a58 58 0 0 1 116 0v124z" fill="url(#vrata-unutra)"/>
        @foreach ($vrste as $i => $vrsta)
            @php($x = [60, 76, 62, 78][$i % 4])
            @php($y = 100 + 26 * $i)
            <g filter="url(#vrata-senka)">
                <rect x="{{ $x }}" y="{{ $y }}" width="{{ 22 + 4.6 * mb_strlen($vrsta->getLabel()) }}" height="17" rx="8.5" fill="#fff" fill-opacity=".82" stroke="#fff" stroke-width=".6"/>
                <circle class="tacka" data-vrsta="{{ $vrsta->value }}" cx="{{ $x + 9 }}" cy="{{ $y + 8.5 }}" r="3.2"/>
                <text x="{{ $x + 16 }}" y="{{ $y + 11.6 }}" font-size="8" font-weight="700" fill="#270f43" font-family="Inter, system-ui, sans-serif">{{ $vrsta->getLabel() }}</text>
            </g>
        @endforeach
        <path d="M138 67.2A58 58 0 0 1 168 118v124h-30z" fill="url(#vrata-krilo)"/>
        <path d="M138 67.2V242" stroke="#fff" stroke-opacity=".55" stroke-width="1.2"/>
        <path d="M40 242V116a70 70 0 0 1 140 0v126" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="5" stroke-linecap="round"/>
    </svg>
</div>
