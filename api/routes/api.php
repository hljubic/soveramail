<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AiRuntimeController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImapAccountController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\LocalDraftController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

// Public: health + the browser-facing OAuth callback (Google redirects here).
Route::get('/health', HealthController::class);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

Route::middleware('api.key')->group(function (): void {
    Route::get('/auth/google/url', [GoogleAuthController::class, 'url']);

    Route::get('/accounts', [AccountController::class, 'index']);
    // IMAP/SMTP mailboxes (iCloud, Yahoo, Fastmail, hosting mail ...): credentials are verified, then stored encrypted.
    Route::post('/accounts/imap', [ImapAccountController::class, 'store'])->middleware('throttle:10,1');
    Route::put('/accounts/{account}/imap', [ImapAccountController::class, 'update'])->middleware('throttle:10,1');
    Route::get('/accounts/{account}', [AccountController::class, 'show']);
    // Removing a mailbox needs an explicit `keep_mail` choice (disconnect vs delete local copy).
    // There is no DELETE for messages: the app never deletes mail.
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);
    Route::post('/accounts/{account}/sync', [AccountController::class, 'sync']);
    Route::get('/accounts/{account}/labels', [AccountController::class, 'labels']);

    // Local categories (exist only in Sovera Mail) and urgency, sorted by the local model.
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/categories/reclassify', [CategoryController::class, 'reclassify']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    Route::get('/accounts/{account}/categories', [CategoryController::class, 'forAccount']);
    Route::post('/accounts/{account}/classify', [CategoryController::class, 'classify']);
    Route::put('/messages/category', [CategoryController::class, 'setForMessages']);
    Route::put('/messages/read-state', [MessageController::class, 'bulkReadState']);
    Route::put('/messages/{message}/category', [CategoryController::class, 'setForMessage']);
    Route::get('/accounts/{account}/contacts', [AccountController::class, 'contacts']);
    Route::get('/accounts/{account}/messages', [MessageController::class, 'index']);

    Route::get('/messages/{message}', [MessageController::class, 'show']);
    Route::patch('/messages/{message}', [MessageController::class, 'update']);
    Route::post('/messages/{message}/acknowledge-risk', [MessageController::class, 'acknowledgeRisk']);
    Route::post('/messages/{message}/reply', [MessageController::class, 'reply'])->middleware('throttle:10,1');
    Route::post('/messages/{message}/forward', [MessageController::class, 'forward'])->middleware('throttle:10,1');
    Route::post('/messages/{message}/draft', [MessageController::class, 'draft']);
    Route::get('/messages/{message}/local-draft', [LocalDraftController::class, 'show']);
    Route::put('/messages/{message}/local-draft', [LocalDraftController::class, 'upsert']);
    Route::delete('/messages/{message}/local-draft', [LocalDraftController::class, 'destroy']);
    Route::post('/accounts/{account}/messages/send', [MessageController::class, 'sendNew'])->middleware('throttle:10,1');
    Route::post('/accounts/{account}/messages/draft', [MessageController::class, 'draftNew']);
    Route::post('/accounts/{account}/ai/draft', [AiController::class, 'draftNew']);
    Route::post('/accounts/{account}/ai/search', [AiController::class, 'smartSearch']);
    Route::get('/accounts/{account}/ai/style-profile', [AiController::class, 'styleProfile']);
    Route::put('/accounts/{account}/ai/style-profile', [AiController::class, 'updateStyleProfile']);
    Route::post('/accounts/{account}/ai/style-profile/rebuild', [AiController::class, 'rebuildStyleProfile']);
    Route::post('/messages/{message}/ai/draft', [AiController::class, 'draft']);
    Route::post('/messages/{message}/ai/summary', [AiController::class, 'summarize']);
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    // Local AI (Ollama) configuration
    Route::get('/ai/settings', [AiController::class, 'settings']);
    Route::put('/ai/settings', [AiController::class, 'updateSettings']);
    Route::post('/ai/probe', [AiController::class, 'probe']);
    Route::post('/ai/language/detect', [AiController::class, 'detectLanguage']);
    Route::get('/ai/index', [AiController::class, 'indexStatus']);
    Route::post('/ai/index', [AiController::class, 'runIndex']);
    Route::post('/ai/rebuild', [AiController::class, 'rebuildAll']);
    Route::get('/ai/runtime', [AiRuntimeController::class, 'status']);
    Route::post('/ai/process', [AiRuntimeController::class, 'process']);
    Route::post('/ai/runtime/unload', [AiRuntimeController::class, 'unload']);
    // What the local AI knows about the user (about, per-category guides and answers, contact cards).
    Route::get('/accounts/{account}/ai/knowledge', [KnowledgeController::class, 'index']);
    Route::post('/accounts/{account}/ai/knowledge/rebuild', [KnowledgeController::class, 'rebuild']);
    Route::put('/ai/knowledge/{item}', [KnowledgeController::class, 'update']);
    Route::post('/ai/knowledge/{item}/reset', [KnowledgeController::class, 'reset']);
});
