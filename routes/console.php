<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('recommendations:expire')->daily();

// IRM ingestion — fetch updated zip archives and re-parse into DB records weekly
Schedule::command('irm:fetch')->weekly();
Schedule::command('irm:parse')->weekly();
