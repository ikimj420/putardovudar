<?php

namespace App\Providers;

use App\Http\Controllers\PomocnikSablonController;
use App\Services\Claude\Claude;
use App\Services\Model\ModelObrade;
use App\Services\Ollama\Ollama;
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
        // Model obrade prilika (MODEL_OBRADE): samo ObradaNacrta i uvoz:obradi traže ModelObrade; pomoćnik i „Napiši moj" traže Ollamu izričito, pa uvek idu lokalno (paket 71).
        // Jedan objekat za ceo prolaz, da se potrošnja zbraja na jednom mestu.
        $this->app->singleton(ModelObrade::class, fn () => config('claude.model_obrade') === 'claude' ? $this->app->make(Claude::class) : $this->app->make(Ollama::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Preko granice posetilac dobija našu stranu sa porukom (429), ne praznu grešku; pitanje ostaje u polju.
        RateLimiter::for('pomocnik', fn (Request $zahtev) => Limit::perMinute((int) config('pomocnik.pitanja_u_minuti'))
            ->by((string) $zahtev->ip())
            ->response(fn (Request $zahtev, array $zaglavlja) => $zahtev->routeIs('pomocnik.napisi')
                ? PomocnikSablonController::previseZahteva($zahtev, $zaglavlja)
                : response()->view($zahtev->ajax() ? 'javno.pomocnik-fragment' : 'javno.pomocnik', [
                    'pitanje' => is_string($zahtev->input('pitanje')) ? $zahtev->input('pitanje') : '',
                    'greska' => PrikazPomocnika::PREVISE_PITANJA,
                ], 429, $zaglavlja)));
    }
}
