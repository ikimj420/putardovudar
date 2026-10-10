{{-- „Napiši moj" za plutajući prozor: samo naslov šablona, napisan tekst i pitanja (bez strane); isti prikaz kao na strani pomoćnika. --}}
<h2 class="plutajuci-sablon">{{ $sablon->naslov }}</h2>
<p class="plutajuci-uvod">{{ $sablon->objasnjenje }}</p>

@include('javno.pomocnik-sablon-obrazac')
