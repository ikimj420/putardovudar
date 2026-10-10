<?php

namespace App\Filament\Widgets;

use App\Enums\StatusObjave;
use App\Enums\StatusPrilike;
use App\Filament\Resources\Organizacije\OrganizacijaResource;
use App\Filament\Resources\Prilike\PrilikaResource;
use App\Filament\Resources\Vodici\VodicResource;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use Filament\Resources\Resource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

// Ivanov pregled: koliko nacrta čeka, a kartica vodi na spisak samo sa njima.
// Tri brojanja su jeftina, pa se crta odmah (bez „Loading..."), i ne osvežava se svakih pet sekundi.
class NacrtiZaPregled extends StatsOverviewWidget
{
    public const PRILIKE = 'Prilike u nacrtu';

    public const VODICI = 'Vodiči u nacrtu';

    public const ORGANIZACIJE = 'Organizacije u nacrtu';

    public const OTVORI = 'Otvori spisak nacrta';

    protected static ?int $sort = -4;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        return [
            self::kartica(self::PRILIKE, Prilika::nacrti()->count(), PrilikaResource::class, StatusPrilike::Nacrt->value),
            self::kartica(self::VODICI, Vodic::nacrti()->count(), VodicResource::class, StatusObjave::Nacrt->value),
            self::kartica(self::ORGANIZACIJE, Organizacija::nacrti()->count(), OrganizacijaResource::class, StatusObjave::Nacrt->value),
        ];
    }

    /** @param  class-string<resource>  $spisak */
    private static function kartica(string $naslov, int $broj, string $spisak, string $status): Stat
    {
        return Stat::make($naslov, $broj)
            ->description(self::OTVORI)
            ->url($spisak::getUrl('index', ['filters' => ['status' => ['value' => $status]]]));
    }
}
