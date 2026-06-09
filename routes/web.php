<?php

use App\Http\Controllers\BotSettingsController;
use App\Http\Controllers\ChatTesterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/bot-settings', [BotSettingsController::class, 'edit'])->name('bot-settings.edit');
    Route::put('/bot-settings', [BotSettingsController::class, 'update'])->name('bot-settings.update');

    Route::get('/chat-tester', [ChatTesterController::class, 'index'])->name('chat-tester.index');
    Route::post('/chat-tester', [ChatTesterController::class, 'store'])->name('chat-tester.store');

    Route::get('/google/connect', [GoogleCalendarController::class, 'connect'])->name('google.connect');
    Route::get('/google/callback', [GoogleCalendarController::class, 'callback'])->name('google.callback');
    Route::delete('/google/disconnect', [GoogleCalendarController::class, 'disconnect'])->name('google.disconnect');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
