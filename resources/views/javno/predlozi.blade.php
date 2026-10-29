@extends('javno.raspored', ['naslov' => \App\Support\PrikazPredloga::NASLOV])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazPredloga::NASLOV, null]]])
    <h1>{{ \App\Support\PrikazPredloga::NASLOV }}</h1>
    <p class="uvod">{{ \App\Support\PrikazPredloga::UVOD }}</p>
@endsection

@section('sadrzaj')
    @if (session('poslato'))
        <p class="ploca hvala" role="status">{{ \App\Support\PrikazPredloga::HVALA }}</p>
    @endif

    @isset($greske['opste'])
        <p class="greska" role="alert">{{ $greske['opste'][0] }}</p>
    @endisset

    <form class="obrazac" method="post" action="{{ route('predlozi.posalji') }}">
        @csrf
        <div class="polje">
            <label for="predlog-link">{{ \App\Support\PrikazPredloga::OZNAKA_LINKA }}</label>
            <p class="polje-objasnjenje" id="objasnjenje-link">{{ \App\Support\PrikazPredloga::OBJASNJENJE_LINKA }}</p>
            <input id="predlog-link" type="text" inputmode="url" autocomplete="off" name="link" maxlength="{{ \App\Models\Predlog::NAJVISE_ZNAKOVA_LINKA }}" value="{{ $link ?? '' }}" required placeholder="{{ \App\Support\PrikazPredloga::PRIMER_LINKA }}" aria-describedby="objasnjenje-link{{ isset($greske['link']) ? ' greska-link' : '' }}"@isset($greske['link']) aria-invalid="true"@endisset>
            @isset($greske['link'])
                <p class="greska" id="greska-link" role="alert">{{ $greske['link'][0] }}</p>
            @endisset
        </div>
        <div class="polje">
            <label for="predlog-napomena">{{ \App\Support\PrikazPredloga::OZNAKA_NAPOMENE }}</label>
            <p class="polje-objasnjenje" id="objasnjenje-napomena">{{ \App\Support\PrikazPredloga::OBJASNJENJE_NAPOMENE }}</p>
            <textarea id="predlog-napomena" name="napomena" rows="4" maxlength="{{ \App\Models\Predlog::NAJVISE_ZNAKOVA_NAPOMENE }}" placeholder="{{ \App\Support\PrikazPredloga::PRIMER_NAPOMENE }}" aria-describedby="objasnjenje-napomena{{ isset($greske['napomena']) ? ' greska-napomena' : '' }}"@isset($greske['napomena']) aria-invalid="true"@endisset>{{ $napomena ?? '' }}</textarea>
            @isset($greske['napomena'])
                <p class="greska" id="greska-napomena" role="alert">{{ $greske['napomena'][0] }}</p>
            @endisset
        </div>
        <button type="submit">{{ \App\Support\PrikazPredloga::DUGME }}</button>
        <p class="privatnost">{{ \App\Support\PrikazPredloga::NE_CUVAMO }}</p>
    </form>
@endsection
