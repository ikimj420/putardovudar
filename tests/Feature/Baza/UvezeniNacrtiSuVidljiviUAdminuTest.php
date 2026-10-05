<?php

namespace Tests\Feature\Baza;

use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Models\Prilika;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class UvezeniNacrtiSuVidljiviUAdminuTest extends AdminBazaTestCase
{
    #[Test]
    public function nacrti_iz_uvoza_su_na_spisku_u_adminu_a_ne_na_javnom_spisku(): void
    {
        Http::preventStrayRequests();
        config()->set('uvoz.izvori', [['ime' => 'Probni izvor', 'adresa' => 'https://probni.test/feed']]);
        Http::fake(['https://probni.test/feed' => Http::response('<rss version="2.0"><channel><item><title>Uvezeni nacrt</title><link>https://probni.test/1</link></item></channel></rss>')]);

        $this->artisan('uvoz:rss');
        $nacrt = Prilika::query()->sole();

        $this->actingAs(User::factory()->create());

        Livewire::test(ListPrilike::class)->assertCanSeeTableRecords([$nacrt]);
        $this->get(route('prilike.index'))->assertDontSee('Uvezeni nacrt');
    }
}
