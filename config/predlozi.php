<?php

return [

    // Koliko predloga jedna adresa sme da pošalje u minuti (adresa se čuva samo u privremenom brojaču ograničenja, nikad uz predlog).
    'slanja_u_minuti' => (int) env('PREDLOZI_SLANJA_U_MINUTI', 3),

];
