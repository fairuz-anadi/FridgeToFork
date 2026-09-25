<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| In the single-container deployment the built React client lives in
| public/, so any non-API URL (e.g. /fridge, /recipes/12/cook) returns the
| client's index.html and React Router takes over. In local development,
| where the client runs on Vite, the welcome view is shown instead.
|
*/

$spa = function () {
    $index = public_path('index.html');

    return is_file($index) ? response()->file($index) : view('welcome');
};

Route::get('/', $spa);
Route::get('/{any}', $spa)->where('any', '^(?!api/).*');
