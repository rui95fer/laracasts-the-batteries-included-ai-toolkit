<?php

use App\Http\Controllers\AIDocumentQAController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KnowledgeSearchController;
use App\Http\Controllers\TicketChatController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketDraftReplyStreamController;
use App\Http\Controllers\TicketMessageController;
use App\Http\Controllers\TicketTriageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('ai/knowledge-search', KnowledgeSearchController::class)
        ->name('ai.knowledge-search');

    Route::get('ai/documents', [AIDocumentQAController::class, 'index'])
        ->name('ai.documents.index');
    Route::post('ai/documents', [AIDocumentQAController::class, 'store'])
        ->name('ai.documents.store');
    Route::post('ai/documents/ask', [AIDocumentQAController::class, 'ask'])
        ->middleware('ai.budget')
        ->name('ai.documents.ask');
    Route::delete('ai/documents/{document}', [AIDocumentQAController::class, 'destroy'])
        ->name('ai.documents.destroy');

    Route::resource('tickets', TicketController::class);

    Route::scopeBindings()->group(function () {
        Route::post('tickets/{ticket}/messages', [TicketMessageController::class, 'store'])
            ->name('tickets.messages.store');
        Route::patch('tickets/{ticket}/messages/{message}', [TicketMessageController::class, 'update'])
            ->name('tickets.messages.update');
        Route::delete('tickets/{ticket}/messages/{message}', [TicketMessageController::class, 'destroy'])
            ->name('tickets.messages.destroy');

        Route::post('tickets/{ticket}/ai/triage', TicketTriageController::class)
            ->middleware('ai.budget')
            ->name('tickets.ai.triage');

        Route::post('tickets/{ticket}/ai/chat', TicketChatController::class)
            ->middleware('ai.budget')
            ->name('tickets.ai.chat');

        Route::post('tickets/{ticket}/ai/draft-reply/stream', TicketDraftReplyStreamController::class)
            ->middleware('ai.budget')
            ->name('tickets.ai.draft-reply.stream');
    });
});

require __DIR__.'/settings.php';
