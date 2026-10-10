@extends('javno.raspored', ['naslov' => 'Prilike'])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], ['Prilike', null]]])
    <h1>Prilike</h1>
@endsection

@section('sadrzaj')
    <nav class="filteri" aria-label="{{ \App\Support\PrikazPrilike::FILTERI }}">
        <a class="filter-pilula{{ $izabrana === null && $izabranaGrupa === null ? ' aktivno' : '' }}" href="{{ route('prilike.index') }}"@if ($izabrana === null && $izabranaGrupa === null) aria-current="true"@endif>{{ \App\Support\PrikazPrilike::SVE_PRILIKE }}</a>
        @foreach ($vrste as $vrsta)
            <a class="filter-pilula{{ $izabrana === $vrsta ? ' aktivno' : '' }}" href="{{ \App\Support\PrikazPrilike::adresaSpiska($vrsta, $izabranaGrupa) }}"@if ($izabrana === $vrsta) aria-current="true"@endif>{{ $vrsta->getLabel() }}</a>
        @endforeach
    </nav>

    @if (count($grupe) > 0)
        <nav class="filteri grupe" aria-labelledby="ja-sam">
            <span class="filteri-naslov" id="ja-sam">{{ \App\Support\PrikazPrilike::JA_SAM }}</span>
            @foreach ($grupe as $grupa)
                {{-- Dodir na izabranu grupu je skida: veza vodi na isti spisak bez nje. --}}
                <a class="filter-pilula{{ $izabranaGrupa === $grupa ? ' aktivno' : '' }}" href="{{ \App\Support\PrikazPrilike::adresaSpiska($izabrana, $izabranaGrupa === $grupa ? null : $grupa) }}"@if ($izabranaGrupa === $grupa) aria-current="true"@endif>{{ $grupa->getLabel() }}</a>
            @endforeach
        </nav>
    @endif

    @forelse ($prilike as $prilika)
        @if ($loop->first)
            <div class="spisak">
        @endif

        @include('javno.kartica', ['prilika' => $prilika])

        @if ($loop->last)
            </div>
        @endif
    @empty
        <p class="prazno">{{ $izabrana !== null && $izabranaGrupa !== null ? \App\Support\PrikazPrilike::NEMA_PRILIKA_ZA_IZBOR : \App\Support\PrikazPrilike::NEMA_PRILIKA }}</p>
    @endforelse
@endsection
