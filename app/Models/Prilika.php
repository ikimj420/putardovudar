<?php

namespace App\Models;

use App\Enums\Grupa;
use App\Enums\KoJeObjavio;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Services\ObradaNacrta;
use App\Support\PoredjenjeTeksta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PrilikaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Larastan ne prepoznaje cast-ove ovog modela (status mu je string), pa tipove koje kod koristi navodimo ovde.
 *
 * @property StatusPrilike $status
 * @property VrstaPrilike $vrsta
 * @property list<string>|null $grupe
 * @property Carbon|null $rok
 * @property KoJeObjavio|null $objavio
 * @property Carbon|null $izvor_objavljen dan kada je izvor objavio stavku (RSS pubDate); služi da se rok bez godine čita kao prvi takav dan posle objave
 * @property Carbon|null $obradeno_at
 * @property array<string, mixed>|null $predlog
 */
#[Fillable(['naslov', 'slug', 'vrsta', 'grupe', 'status', 'kratak_opis', 'opis', 'rok', 'rok_stalno_otvoren', 'mesto', 'online', 'naziv_izvora', 'link_izvora', 'izvor_objavljen', 'obradeno_at', 'objavio', 'razlog_objave', 'predlog'])]
class Prilika extends Model
{
    /** @use HasFactory<PrilikaFactory> */
    use HasFactory;

    public const PORUKA_BEZ_IZVORA = 'Za objavu je potreban link izvora.';

    protected $table = 'prilike';

    // Isto kao podrazumevane vrednosti u bazi: nov objekat ne sme da ima prazan status.
    protected $attributes = [
        'status' => 'nacrt',
        'rok_stalno_otvoren' => false,
        'online' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (Prilika $prilika): void {
            if (blank($prilika->slug)) {
                $prilika->slug = self::slugOd((string) $prilika->naslov);
            }

            if ($prilika->status->zahtevaIzvor() && ! self::jeLink($prilika->link_izvora)) {
                throw ValidationException::withMessages(['link_izvora' => self::PORUKA_BEZ_IZVORA]);
            }

            // Grupe se čuvaju kao vrednosti iz enuma, u njegovom redosledu, bez ponavljanja; prazno je null.
            $grupe = array_map(fn (Grupa $grupa) => $grupa->value, Grupa::izVrednosti($prilika->grupe));
            $prilika->grupe = $grupe === [] ? null : $grupe;

            // Objava koja nije prošla kroz objavi() je ručna: čovek je promenio status u admin.
            if ($prilika->isDirty('status') && $prilika->status === StatusPrilike::Objavljeno && ! $prilika->isDirty('objavio')) {
                $prilika->objavio = KoJeObjavio::Covek;
            }
        });
    }

    // Posle uvoza ili obrade niko nije menjao zapis: obrada postavlja obradeno_at u istom trenutku kad i izmenu, a Ivanova izmena kasnije pomera updated_at.
    // Zapis koji niko nije obradio poredi se sa trenutkom pravljenja. Minut tolerancije za vreme upisa.
    public function nijeMenjanRucno(): bool
    {
        $polazna = $this->obradeno_at ?? $this->created_at;

        return $this->updated_at !== null && $polazna !== null && $this->updated_at->lessThanOrEqualTo($polazna->copy()->addMinute());
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'vrsta' => VrstaPrilike::class,
            'grupe' => 'array',
            'status' => StatusPrilike::class,
            'rok' => 'date',
            'rok_stalno_otvoren' => 'boolean',
            'online' => 'boolean',
            'izvor_objavljen' => 'date',
            'obradeno_at' => 'datetime',
            'objavio' => KoJeObjavio::class,
            'predlog' => 'array',
        ];
    }

    /** @return list<Grupa> */
    public function izabraneGrupe(): array
    {
        return Grupa::izVrednosti($this->grupe);
    }

    // Jedino mesto gde se odlučuje da li je rok prošao: ima rok, prijave nisu stalno otvorene, a rok je pre današnjeg dana
    // (rok „danas" nije prošao). „Danas" je po zoni aplikacije (Europe/Belgrade). Javne prilike i oznaka u adminu pitaju ovde.
    public const NAJVISE_ZNAKOVA_U_PRETRAZI = 100;

    private const NAJVISE_RECI_U_PRETRAZI = 8;

    private const ROK_PROSAO = 'prilike.rok_stalno_otvoren = 0 and prilike.rok is not null and prilike.rok < ?';

    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function rokProsao(Builder $upit): void
    {
        $upit->whereRaw(self::ROK_PROSAO, [today()->toDateString()]);
    }

    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function rokNijeProsao(Builder $upit): void
    {
        $upit->whereRaw('not ('.self::ROK_PROSAO.')', [today()->toDateString()]);
    }

    // Prilike koje idu u kalendar (paket 81): objavljene, rok prošao, a vrsta nije „posao" (oglas za posao se ne ponavlja svake godine; predlog iz paketa,
    // Ivan može drugačije). Jedino mesto tog pravila; komanda kalendar:iz-prilika pita ovde.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function zaKalendar(Builder $upit): void
    {
        $upit->where('status', StatusPrilike::Objavljeno)->where('vrsta', '!=', VrstaPrilike::Posao)->rokProsao();
    }

    // Koliko dana unapred se rok računa kao „uskoro" (paket 61); jedini broj, pa početna i spisak ne mogu da se razilaze.
    public const DANA_USKORO = 14;

    // Rok od danas do danas + DANA_USKORO, oba dana uključena (zona aplikacije, kao rokProsao); bez roka i stalno otvorene ne ulaze.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function rokUskoro(Builder $upit): void
    {
        $upit->where('prilike.rok_stalno_otvoren', false)->whereBetween('prilike.rok', [today()->toDateString(), today()->addDays(self::DANA_USKORO)->toDateString()]);
    }

    // Isti uslov kao rokProsao, ali kao kolona uz svaki zapis spiska, da kartica ne pita bazu za svaki zapis posebno.
    /**
     * @param  Builder<Prilika>  $upit
     * @return Builder<Prilika>
     */
    public static function saOznakomRoka(Builder $upit): Builder
    {
        return $upit->addSelect('prilike.*')->selectRaw('('.self::ROK_PROSAO.') as rok_prosao', [today()->toDateString()]);
    }

    public function rokJeProsao(): bool
    {
        return (bool) ($this->attributes['rok_prosao'] ?? self::query()->rokProsao()->whereKey($this->getKey())->exists());
    }

    // Jedino mesto gde se odlučuje šta je javno.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function javne(Builder $upit): void
    {
        $upit->where('status', StatusPrilike::Objavljeno)->rokNijeProsao();
    }

    // Najbliži rok prvo; bez roka i stalno otvorene na kraju, jer im rok ne važi. Spisak i početna se slažu isto.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function poRoku(Builder $upit): void
    {
        $upit->orderByRaw('rok IS NULL OR rok_stalno_otvoren')->orderBy('rok')->orderBy('id');
    }

    // Pretraga sajta (paket 57): svaka reč iz upita mora da stoji u naslovu ili kratkom opisu. Reči se porede bez dijakritika i velikih slova (kolacija baze),
    // po početku reči (padeži: „Nišu" nalazi „Niš"), a reči od jednog slova se ne računaju; upit bez ijedne reči ne sužava ništa.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function pretraga(Builder $upit, ?string $tekst): void
    {
        foreach (self::reciZaPretragu($tekst) as $rec) {
            $upit->where(fn (Builder $uslov) => $uslov->where('naslov', 'like', '%'.$rec.'%')->orWhere('kratak_opis', 'like', '%'.$rec.'%'));
        }
    }

    /** @return list<string> normalizovane reči (samo slova i cifre, pa nema džokera za LIKE), najviše osam */
    public static function reciZaPretragu(?string $tekst): array
    {
        $reci = array_filter(explode(' ', PoredjenjeTeksta::normalizuj((string) $tekst)), fn (string $rec) => strlen($rec) >= 2);

        return array_slice(array_values(array_unique(array_map(PoredjenjeTeksta::stablo(...), $reci))), 0, self::NAJVISE_RECI_U_PRETRAZI);
    }

    // Vrste koje imaju bar jednu javnu priliku, redom kojim su nabrojane u VrstaPrilike; dugme i oznaka postoje samo za njih.
    /** @return list<VrstaPrilike> */
    public static function vrsteSaJavnimPrilikama(): array
    {
        $postojece = self::query()->javne()->distinct()->pluck('vrsta');

        return array_values(array_filter(VrstaPrilike::cases(), fn (VrstaPrilike $vrsta) => $postojece->contains($vrsta)));
    }

    // Jedino mesto gde se odlučuje šta je nacrt za pregled; admin pita ovde.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function nacrti(Builder $upit): void
    {
        $upit->where('status', StatusPrilike::Nacrt);
    }

    public function jeNacrt(): bool
    {
        return $this->status === StatusPrilike::Nacrt;
    }

    // Jedino mesto gde se arhivira (paket 86): samo nacrt; objavljena prilika se ovde ne dira, a arhivirana ne ide ni u obradu ni na sajt.
    public function arhiviraj(): bool
    {
        if (! $this->jeNacrt()) {
            return false;
        }

        $this->status = StatusPrilike::Arhivirano;
        $this->save();

        return true;
    }

    // Koliko dana posle roka nacrt čeka pre nego što ga dnevni posao arhivira (paket 86/T2b, Ivan 30.10.2026.).
    public const DANA_DO_ARHIVIRANJA = 30;

    // Nacrt kome je rok prošao pre više od DANA_DO_ARHIVIRANJA dana: po koloni „rok", a kad je ona prazna, po datumu iz predloga obrade (predlog.rok) uz razlog „rok je prošao" (dokazan citatom
    // sa strane). Drugi razlozi se ne računaju: tamo je datum samo tvrdnja modela (paket 96, PITANJA-86 3). Objavljene i arhivirane se ne hvataju.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function zaArhiviranje(Builder $upit): void
    {
        $granica = today()->subDays(self::DANA_DO_ARHIVIRANJA)->toDateString();

        $upit->nacrti()->where(fn (Builder $uslov) => $uslov
            ->where(fn (Builder $poKoloni) => $poKoloni->rokProsao()->where('prilike.rok', '<', $granica))
            ->orWhere(fn (Builder $poPredlogu) => $poPredlogu->nacrtZbog(ObradaNacrta::RAZLOG_ROK_PROSAO)
                ->whereNull('prilike.rok')
                ->where('prilike.rok_stalno_otvoren', false)
                ->whereRaw("json_unquote(json_extract(prilike.predlog, '$.rok')) regexp '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'")
                ->whereRaw("json_unquote(json_extract(prilike.predlog, '$.rok')) < ?", [$granica])));
    }

    // Jedino mesto gde se objavljena prilika vraća u nacrt zbog provere sa stranom izvora (paket 85). Razlog ide u predlog.odbijeno, kao razlozi obrade, pa ga admin vidi u izboru
    // „Zašto je nacrt"; šta piše na strani ide u predlog.provera.
    public function vratiUNacrt(string $razlog, string $citat): void
    {
        $this->status = StatusPrilike::Nacrt;
        $this->predlog = [...($this->predlog ?? []), 'odbijeno' => $razlog, 'provera' => ['ishod' => 'nacrt', 'razlog' => $razlog, 'citat' => $citat, 'datum' => today()->toDateString()]];
        $this->save();
    }

    // Nov rok koji strana sama piše (paket 85). Stari rok ostaje u predlog.provera; predlog.rok i predlog.citat se slažu sa novim, da ga provera roka bez godine ne bi dirala.
    public function promeniRok(CarbonInterface $rok, string $citat): void
    {
        $this->predlog = [...($this->predlog ?? []), 'rok' => $rok->toDateString(), 'citat' => $citat, 'provera' => ['ishod' => 'rok', 'rok_pre' => $this->rok?->toDateString(), 'rok_posle' => $rok->toDateString(), 'citat' => $citat, 'datum' => today()->toDateString()]];
        $this->setAttribute('rok', $rok->toDateString());
        $this->save();
    }

    // Strana koje nema računa se kao „ne postoji" tek kad je nema dva dana zaredom (paket 85): prvi dan se samo upiše, a svaki odgovor strane brisanje vraća na početak.
    public function nedostupnaOd(): ?CarbonImmutable
    {
        $dan = data_get($this->predlog, 'nedostupna_od');

        return is_string($dan) ? CarbonImmutable::parse($dan)->startOfDay() : null;
    }

    public function zapamtiNedostupnu(): void
    {
        $this->predlog = [...($this->predlog ?? []), 'nedostupna_od' => today()->toDateString()];
        $this->save();
    }

    public function zaboraviNedostupnu(): void
    {
        $predlog = $this->predlog ?? [];

        if (array_key_exists('nedostupna_od', $predlog)) {
            unset($predlog['nedostupna_od']);
            $this->predlog = $predlog === [] ? null : $predlog;
            $this->save();
        }
    }

    private const RAZLOG_JE_TEKST = "json_type(json_extract(predlog, '$.odbijeno')) = 'STRING'";

    // Nacrt koji je obrada ostavila iz datog razloga (predlog.odbijeno, tekst koji piše obrada). JSON null („nema razloga") nije razlog, pa ga upit ne hvata.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function nacrtZbog(Builder $upit, string $razlog): void
    {
        $upit->nacrti()->whereRaw(self::RAZLOG_JE_TEKST)->whereRaw("json_unquote(json_extract(predlog, '$.odbijeno')) = ?", [$razlog]);
    }

    /** @return list<string> razlozi koje nacrti u bazi stvarno imaju, po abecedi; za izbor u adminu */
    public static function razloziNacrta(): array
    {
        return self::query()->nacrti()->whereRaw(self::RAZLOG_JE_TEKST)
            ->selectRaw("distinct json_unquote(json_extract(predlog, '$.odbijeno')) as razlog")->orderBy('razlog')->pluck('razlog')->all();
    }

    /** @return list<string> imena izvora koja prilike u bazi stvarno imaju, po abecedi; za izbor u adminu */
    public static function izvori(): array
    {
        return self::query()->whereNotNull('naziv_izvora')->where('naziv_izvora', '!=', '')->distinct()->orderBy('naziv_izvora')->pluck('naziv_izvora')->all();
    }

    private static function jeLink(?string $vrednost): bool
    {
        $link = trim((string) $vrednost);

        return $link !== ''
            && filter_var($link, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    // Objava kroz model: pravilo o izvoru (saving) i dalje važi, a ko je objavio ostaje zapisano.
    public function objavi(KoJeObjavio $ko): void
    {
        $this->status = StatusPrilike::Objavljeno;
        $this->objavio = $ko;
        $this->save();
    }

    // Isti naslov ne sme da završi sa istim slugom: drugi dobija -2, treći -3.
    private static function slugOd(string $naslov): string
    {
        $osnova = Str::slug($naslov);
        $slug = $osnova;

        for ($broj = 2; self::query()->where('slug', $slug)->exists(); $broj++) {
            $slug = $osnova.'-'.$broj;
        }

        return $slug;
    }
}
