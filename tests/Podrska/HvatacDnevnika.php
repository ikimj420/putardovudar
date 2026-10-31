<?php

namespace Tests\Podrska;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

// Hvata sve što aplikacija upiše u dnevnik od trenutka pravljenja, da test meri šta je upisano, a ne šta je trebalo da bude.
final class HvatacDnevnika
{
    /** @var list<MessageLogged> */
    public array $poruke = [];

    public function __construct()
    {
        Event::listen(MessageLogged::class, function (MessageLogged $poruka): void {
            $this->poruke[] = $poruka;
        });
    }
}
