<?php

namespace App\Policies;

use App\Models\KalendarStavka;
use App\Models\User;

// Jedino mesto koje kaže ko sme šta sa stavkama kalendara. Uloga još nema, pa svaki prijavljen korisnik sme da vidi, doda i izmeni; brisanja nema.
class KalendarStavkaPolicy
{
    public function viewAny(User $korisnik): bool
    {
        return true;
    }

    public function view(User $korisnik, KalendarStavka $stavka): bool
    {
        return true;
    }

    public function create(User $korisnik): bool
    {
        return true;
    }

    public function update(User $korisnik, KalendarStavka $stavka): bool
    {
        return true;
    }

    public function delete(User $korisnik, KalendarStavka $stavka): bool
    {
        return false;
    }

    public function deleteAny(User $korisnik): bool
    {
        return false;
    }

    public function restore(User $korisnik, KalendarStavka $stavka): bool
    {
        return false;
    }

    public function forceDelete(User $korisnik, KalendarStavka $stavka): bool
    {
        return false;
    }
}
