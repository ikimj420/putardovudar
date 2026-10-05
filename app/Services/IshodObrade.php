<?php

namespace App\Services;

final readonly class IshodObrade
{
    public const OBJAVA = 'objava';

    public const NACRT = 'nacrt';

    public const NIJE_OBRADJENO = 'nije_obradjeno';

    public function __construct(
        public string $ishod,
        public string $razlog = '',
    ) {}
}
