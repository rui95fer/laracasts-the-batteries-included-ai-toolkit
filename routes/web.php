<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TicketChatController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketMessageController;
use App\Http\Controllers\TicketTriageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('tickets', TicketController::class);

    Route::scopeBindings()->group(function () {
        Route::post('tickets/{ticket}/messages', [TicketMessageController::class, 'store'])
            ->name('tickets.messages.store');
        Route::patch('tickets/{ticket}/messages/{message}', [TicketMessageController::class, 'update'])
            ->name('tickets.messages.update');
        Route::delete('tickets/{ticket}/messages/{message}', [TicketMessageController::class, 'destroy'])
            ->name('tickets.messages.destroy');

        Route::post('tickets/{ticket}/ai/triage', TicketTriageController::class)
            ->name('tickets.ai.triage');

        Route::post('tickets/{ticket}/ai/chat', TicketChatController::class)
            ->name('tickets.ai.chat');
    });
});

require __DIR__.'/settings.php';
