<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recommendation Expiration
    |--------------------------------------------------------------------------
    |
    | Pending alert and case recommendations older than this window are marked
    | expired by the scheduled recommendation expiration command.
    |
    */

    'expiration_days' => (int) env('RECOMMENDATION_EXPIRATION_DAYS', 30),

];
