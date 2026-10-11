<?php

namespace App\Enums;

// Rod u kom se piše tekst za čoveka („radio sam" / „radila sam"); jedino mesto koje zna koji je suprotan.
enum Rod: string
{
    case Muski = 'muski';
    case Zenski = 'zenski';

    public function suprotan(): self
    {
        return match ($this) {
            self::Muski => self::Zenski,
            self::Zenski => self::Muski,
        };
    }
}
