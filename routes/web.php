<?php

use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/privacy-policy', [LegalController::class, 'privacyPolicy'])
    ->name('legal.privacy-policy');

Route::get('/account-deletion', [LegalController::class, 'showAccountDeletion'])
    ->name('legal.account-deletion');

Route::post('/account-deletion', [LegalController::class, 'submitAccountDeletion'])
    ->middleware('throttle:5,1')
    ->name('legal.account-deletion.submit');
