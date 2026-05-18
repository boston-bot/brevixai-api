<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/alert-recommendations', function () {
    return view('alert-recommendations.index');
});
