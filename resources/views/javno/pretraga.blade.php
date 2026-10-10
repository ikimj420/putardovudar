{{-- Jedno polje za pretragu prilika (obična forma, bez skripta): $pretraga je ono što je upisano, $izabrana izabrana vrsta (ostaje uz pretragu). --}}
<form class="pretraga" method="get" action="{{ route('prilike.index') }}" role="search">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input type="search" name="q" value="{{ $pretraga ?? '' }}" maxlength="{{ \App\Models\Prilika::NAJVISE_ZNAKOVA_U_PRETRAZI }}" placeholder="{{ \App\Support\PrikazPrilike::PRETRAGA_PRIMER }}" aria-label="{{ \App\Support\PrikazPrilike::PRETRAGA_NAZIV }}">
    @if (($izabrana ?? null) !== null)
        <input type="hidden" name="vrsta" value="{{ $izabrana->value }}">
    @endif
    <button class="dugme dugme-glavno dugme-malo" type="submit">{{ \App\Support\PrikazPrilike::PRETRAGA_DUGME }}</button>
</form>
