<?php

use App\Http\Controllers\SlackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/slack/fetch-old', [SlackController::class,'fetchOldMessages']);
