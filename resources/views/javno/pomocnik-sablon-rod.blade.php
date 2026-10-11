{{-- Dugmad za rod (paket 64): mali obrazac sa istim poljima kao glavni, pa dugme ponovo piše tekst u izabranom rodu. Polja idu u telu zahteva, nikad u adresi, i ništa se ne čuva. --}}
<form class="rod-forma" method="post" action="{{ route('pomocnik.napisi', $sablon->slug) }}" data-pomocnik-sablon-forma>
    @csrf
    @foreach ($sablon->polja as $polje)
        <input type="hidden" name="polje[{{ $polje['kljuc'] }}]" value="{{ $unosi[$polje['kljuc']] ?? '' }}">
    @endforeach
    @if (($izabranRod ?? null) !== null)
        <input type="hidden" name="izabran_rod" value="{{ $izabranRod->value }}">
    @endif
    @foreach ($izbori as $vrednost => $natpis)
        <button class="{{ ($veza ?? false) ? 'veza-dugme' : 'dugme-rod' }}" type="submit" name="rod" value="{{ $vrednost }}">{{ $natpis }}</button>
    @endforeach
</form>
