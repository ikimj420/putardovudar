<?php

namespace App\Providers;

use App\Support\PrikazPomocnika;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Preko granice posetilac dobija našu stranu sa porukom (429), ne praznu grešku; pitanje ostaje u polju.
        RateLimiter::for('pomocnik', fn (Request $zahtev) => Limit::perMinute((int) config('pomocnik.pitanja_u_minuti'))
            ->by((string) $zahtev->ip())
            ->response(fn (Request $zahtev, array $zaglavlja) => response()->view('javno.pomocnik', [
                'pitanje' => is_string($zahtev->input('pitanje')) ? $zahtev->input('pitanje') : '',
                'greska' => PrikazPomocnika::PREVISE_PITANJA,
            ], 429, $zaglavlja)));
    }
}
