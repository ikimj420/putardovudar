@extends('javno.raspored', ['naslov' => $sablon->naslov])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazSablona::NASLOV, route('sabloni.index')], [$sablon->naslov, null]]])
    <h1>{{ $sablon->naslov }}</h1>
    <p class="kratko">{{ $sablon->objasnjenje }}</p>
@endsection

@section('sadrzaj')
    @if ($tekst !== null)
        <section class="ploca sklopljen" aria-labelledby="tvoj-tekst">
            <h2 id="tvoj-tekst">{{ \App\Support\PrikazSablona::NASLOV_TEKSTA }}</h2>
            <p class="uputstvo">{{ \App\Support\PrikazSablona::UPUTSTVO_TEKSTA }}</p>
            <div class="sklopljen-tekst">{{ $tekst }}</div>
        </section>
    @endif

    <form class="obrazac" method="post" action="{{ route('sabloni.sklopi', $sablon->slug) }}">
        @csrf
        @foreach ($sablon->polja as $polje)
            <div class="polje">
                <label for="polje-{{ $polje['kljuc'] }}">{{ $polje['oznaka'] }}</label>
                @if (filled($polje['objasnjenje'] ?? null))
                    <p class="polje-objasnjenje" id="objasnjenje-{{ $polje['kljuc'] }}">{{ $polje['objasnjenje'] }}</p>
                @endif
                @if ($polje['dugo'] ?? false)
                    <textarea id="polje-{{ $polje['kljuc'] }}" name="{{ $polje['kljuc'] }}" rows="5" maxlength="{{ \App\Support\SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA }}"@if (filled($polje['objasnjenje'] ?? null)) aria-describedby="objasnjenje-{{ $polje['kljuc'] }}"@endif>{{ $unosi[$polje['kljuc']] ?? '' }}</textarea>
                @else
                    <input id="polje-{{ $polje['kljuc'] }}" type="text" name="{{ $polje['kljuc'] }}" maxlength="255" value="{{ $unosi[$polje['kljuc']] ?? '' }}"@if (filled($polje['objasnjenje'] ?? null)) aria-describedby="objasnjenje-{{ $polje['kljuc'] }}"@endif>
                @endif
            </div>
        @endforeach
        <button type="submit">{{ \App\Support\PrikazSablona::DUGME }}</button>
    </form>
@endsection
