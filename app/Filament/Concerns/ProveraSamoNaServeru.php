<?php

namespace App\Filament\Concerns;

use Filament\Schemas\Components\Component;

trait ProveraSamoNaServeru
{
    // Bez novalidate pregledač pokaže svoju poruku na jeziku pregledača („Please fill out this field.") umesto srpske sa servera.
    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()->extraAttributes(['novalidate' => true]);
    }
}
