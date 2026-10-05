@extends('javno.raspored', ['naslov' => $prilika->naslov])

@section('sadrzaj')
    <article>
        <h1>{{ $prilika->naslov }}</h1>
        <p>{{ $prilika->vrsta->getLabel() }}</p>
        <p>{{ \App\Support\PrikazPrilike::rok($prilika) }}</p>
        @if (\App\Support\PrikazPrilike::mesto($prilika))
            <p>{{ \App\Support\PrikazPrilike::mesto($prilika) }}</p>
        @endif
        <p>{{ $prilika->kratak_opis }}</p>
        @if (filled($prilika->opis))
            <div>{!! nl2br(e($prilika->opis)) !!}</div>
        @endif

        @if (\App\Support\PrikazPrilike::nazivIzvora($prilika))
            <p>
                {{ \App\Support\PrikazPrilike::ZVANICNI_IZVOR }}
                @if (\App\Support\PrikazPrilike::linkIzvora($prilika))
                    <a href="{{ \App\Support\PrikazPrilike::linkIzvora($prilika) }}" rel="noopener noreferrer nofollow" target="_blank">{{ \App\Support\PrikazPrilike::nazivIzvora($prilika) }}</a>
                @else
                    {{ \App\Support\PrikazPrilike::nazivIzvora($prilika) }}
                @endif
            </p>
            <p>{{ \App\Support\PrikazPrilike::PROVERI_IZVOR }}</p>
        @endif
    </article>
@endsection
