{{-- Odgovor za plutajući prozor: samo ono što stiže posle pitanja (bez strane); ista pravila i isti prikaz kao na /pomocnik. --}}
@isset($greska)
    <p class="greska" role="alert">{{ $greska }}</p>
@endisset

@include('javno.pomocnik-rezultat')
