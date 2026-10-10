@extends('javno.raspored', ['naslov' => $prilika->naslov])

@section('traka')
    @include('javno.mrvice', ['delovi' => [[config('app.name'), route('pocetna')], ['Prilike', route('prilike.index')], [$prilika->naslov, null]]])
    <h1>{{ $prilika->naslov }}</h1>
    <div class="oznake">
        <span class="oznaka">{{ $prilika->vrsta->getLabel() }}</span>
        <span class="meta-stavka">{{ \App\Support\PrikazPrilike::rok($prilika) }}</span>
        @if (\App\Support\PrikazPrilike::mesto($prilika))
            <span class="meta-stavka">{{ \App\Support\PrikazPrilike::mesto($prilika) }}</span>
        @endif
    </div>
    <p class="kratko">{{ $prilika->kratak_opis }}</p>
@endsection

@section('sadrzaj')
    <div class="{{ \App\Support\PrikazPrilike::nazivIzvora($prilika) && filled($prilika->opis) ? 'detalj sa-bocnim' : 'detalj' }}">
        <article class="prilika">
            @if (filled($prilika->opis))
                <div class="ploca">
                    <div class="sadrzaj-opis">{!! nl2br(e($prilika->opis)) !!}</div>
                </div>
            @endif
        </article>

        @if (\App\Support\PrikazPrilike::nazivIzvora($prilika))
            <div class="izvor">
                <p>
                    {{ \App\Support\PrikazPrilike::ZVANICNI_IZVOR }}
                    @if (\App\Support\PrikazPrilike::linkIzvora($prilika))
                        <a href="{{ \App\Support\PrikazPrilike::linkIzvora($prilika) }}" rel="noopener noreferrer nofollow" target="_blank">{{ \App\Support\PrikazPrilike::nazivIzvora($prilika) }}</a>
                    @else
                        {{ \App\Support\PrikazPrilike::nazivIzvora($prilika) }}
                    @endif
                </p>
                <p class="izvor-upozorenje">{{ \App\Support\PrikazPrilike::PROVERI_IZVOR }}</p>
            </div>
        @endif
    </div>
@endsection
