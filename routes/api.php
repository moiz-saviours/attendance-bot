<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\SlackController;

Route::post('/slack/attendance', [SlackController::class,'attendance']);
