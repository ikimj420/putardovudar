@extends('javno.raspored', ['naslov' => $vodic->naslov])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], [\App\Support\PrikazVodica::NASLOV, route('vodici.index')], [$vodic->naslov, null]]])
    <h1>{{ $vodic->naslov }}</h1>
    <p class="kratko">{{ $vodic->kratak_opis }}</p>
@endsection

@section('sadrzaj')
    <article class="prilika detalj">
        <div class="ploca">
            <div class="sadrzaj-opis">{!! nl2br(e($vodic->tekst)) !!}</div>
        </div>

        @if (count($vodic->koraci ?? []) > 0)
            <div class="ploca">
                <h2 class="naslov-izvora">{{ \App\Support\PrikazVodica::KORACI }}</h2>
                <ol class="koraci">
                    @foreach ($vodic->koraci as $korak)
                        <li>{{ $korak }}</li>
                    @endforeach
                </ol>
            </div>
        @endif
    </article>
@endsection
