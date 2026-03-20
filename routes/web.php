<?php

use App\Http\Controllers\SlackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/slack-message', [SlackController::class, 'showMessageForm']);
Route::post('/slack-message', [SlackController::class, 'sendMessageFromForm'])->name('slack.send');


Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
