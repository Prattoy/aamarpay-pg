<?php

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AamarpayController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('payments')->middleware('payment.logger')->group(function () {
    Route::post('/initiate', [AamarpayController::class, 'initiate'])->middleware('throttle:10,1');
    Route::post('/callback/success', [AamarpayController::class, 'success']);
    Route::post('/callback/fail', [AamarpayController::class, 'fail']);
    Route::get('/callback/cancel', [AamarpayController::class, 'cancel']);
    Route::post('/callback/pg-webhook', [AamarpayController::class, 'pgWebhook']);
});

Route::post('/webhook', [Controller::class, 'webhook_test']);
