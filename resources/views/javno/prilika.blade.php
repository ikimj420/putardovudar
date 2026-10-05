@extends('javno.raspored', ['naslov' => $prilika->naslov])

@section('sadrzaj')
    <article class="prilika">
        <h1>{{ $prilika->naslov }}</h1>
        <p><span class="oznaka">{{ $prilika->vrsta->getLabel() }}</span></p>
        <p class="red">{{ \App\Support\PrikazPrilike::rok($prilika) }}</p>
        @if (\App\Support\PrikazPrilike::mesto($prilika))
            <p class="red">{{ \App\Support\PrikazPrilike::mesto($prilika) }}</p>
        @endif
        <p class="kratko">{{ $prilika->kratak_opis }}</p>
        @if (filled($prilika->opis))
            <div class="sadrzaj-opis">{!! nl2br(e($prilika->opis)) !!}</div>
        @endif

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
    </article>
@endsection
