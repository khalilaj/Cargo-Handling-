<?php

return [

    /*
    | More demo data (see ExtraDemoSeeder): a fuller yard with history for the demo.
    | On by default so the live demo shows it on its next boot; switch off with SEED_EXTRA_DEMO=false.
    | The test suite turns it off so its fixed counts stay fixed.
    */
    'extra' => (bool) env('SEED_EXTRA_DEMO', true),

];
