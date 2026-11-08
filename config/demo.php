<?php

return [

    /*
    | The live demo yard (see DemoYardSeeder): three tanks, ships in port, trucks on site, with history.
    | When on, it replaces the base demo set in DatabaseSeeder (the users are seeded either way).
    | On by default; switch off with SEED_EXTRA_DEMO=false. The test suite turns it off so its fixed counts stay fixed.
    */
    'extra' => (bool) env('SEED_EXTRA_DEMO', true),

];
