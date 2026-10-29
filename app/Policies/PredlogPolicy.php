<?php

namespace App\Policies;

use App\Models\Predlog;
use App\Models\User;

// Jedino mesto koje kaže ko sme šta sa predlozima posetilaca. Uloga još nema, pa svaki prijavljen korisnik sme da vidi, označi pregledanim i obriše; predlog se ne pravi u adminu.
class PredlogPolicy
{
    public function viewAny(User $korisnik): bool
    {
        return true;
    }

    public function view(User $korisnik, Predlog $predlog): bool
    {
        return true;
    }

    public function create(User $korisnik): bool
    {
        return false;
    }

    public function update(User $korisnik, Predlog $predlog): bool
    {
        return true;
    }

    public function delete(User $korisnik, Predlog $predlog): bool
    {
        return true;
    }

    public function deleteAny(User $korisnik): bool
    {
        return false;
    }

    public function restore(User $korisnik, Predlog $predlog): bool
    {
        return false;
    }

    public function forceDelete(User $korisnik, Predlog $predlog): bool
    {
        return false;
    }
}
