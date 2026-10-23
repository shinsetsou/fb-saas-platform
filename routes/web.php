<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\BotSettingsController;
use App\Http\Controllers\ChatTesterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookController;
use App\Http\Controllers\FacebookWebhookController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Facebook calls these — no auth session, CSRF excluded in bootstrap/app.php.
Route::get('/webhooks/facebook', [FacebookWebhookController::class, 'verify'])->name('facebook.webhook.verify');
Route::post('/webhooks/facebook', [FacebookWebhookController::class, 'receive'])->name('facebook.webhook.receive');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/bot-settings', [BotSettingsController::class, 'edit'])->name('bot-settings.edit');
    Route::put('/bot-settings', [BotSettingsController::class, 'update'])->name('bot-settings.update');

    Route::get('/chat-tester', [ChatTesterController::class, 'index'])->name('chat-tester.index')->middleware('throttle:60,1');
    Route::post('/chat-tester', [ChatTesterController::class, 'store'])->name('chat-tester.store')->middleware('throttle:30,1');

    Route::get('/google/connect', [GoogleCalendarController::class, 'connect'])->name('google.connect');
    Route::get('/google/callback', [GoogleCalendarController::class, 'callback'])->name('google.callback');
    Route::delete('/google/disconnect', [GoogleCalendarController::class, 'disconnect'])->name('google.disconnect');

    Route::get('/facebook/connect', [FacebookController::class, 'connect'])->name('facebook.connect');
    Route::get('/facebook/callback', [FacebookController::class, 'callback'])->name('facebook.callback');
    Route::delete('/facebook/disconnect', [FacebookController::class, 'disconnect'])->name('facebook.disconnect');

    Route::get('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
