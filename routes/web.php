<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Meta webhook (public, verified by signature).
Route::get('/webhook/meta', [WebhookController::class, 'verify'])->name('webhook.verify');
Route::post('/webhook/meta', [WebhookController::class, 'receive'])->name('webhook.receive');

// Public privacy policy and data deletion instructions for Meta App Review.
Route::view('/privacy', 'privacy')->name('privacy');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Inbox: everyone, limited to the pages they can access.
    Route::get('/', [InboxController::class, 'index'])->name('inbox');
    Route::get('/inbox/conversations', [InboxController::class, 'conversations']);
    Route::middleware('can:access-conversation,conversation')
        ->prefix('/inbox/conversations/{conversation}')
        ->group(function () {
            Route::get('/messages', [InboxController::class, 'messages']);
            Route::post('/read', [InboxController::class, 'read']);
            Route::post('/reply', [InboxController::class, 'reply']);
            Route::post('/suggest', [InboxController::class, 'suggest']);
            Route::get('/products', [InboxController::class, 'products']);
            Route::post('/product-media', [InboxController::class, 'sendProductMedia']);
            Route::post('/upload', [InboxController::class, 'upload']);
            Route::get('/orders', [OrderController::class, 'forConversation']);
            Route::post('/orders', [OrderController::class, 'storeFromConversation']);
            Route::patch('/', [InboxController::class, 'update']);
        });

    // Orders: everyone, limited to their pages (deleting is admin-only).
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/export', [OrderController::class, 'export'])->name('orders.export');
    Route::get('/orders/new', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'edit'])->name('orders.edit');
    Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');

    // Products: everyone; agents only for their own pages.
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/new', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::delete('/product-media/{media}', [ProductController::class, 'destroyMedia'])->name('products.media.destroy');

    // Admin only.
    Route::middleware('admin')->group(function () {
        Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
        Route::get('/pages/connect', [PageController::class, 'connect'])->name('pages.connect');
        Route::get('/pages/callback', [PageController::class, 'callback'])->name('pages.callback');
        Route::patch('/pages/{page}', [PageController::class, 'update'])->name('pages.update');
        Route::post('/pages/{page}/resubscribe', [PageController::class, 'resubscribe'])->name('pages.resubscribe');
        Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update']);
        Route::post('/settings/test', [SettingsController::class, 'test'])->name('settings.test');

        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamController::class, 'store'])->name('team.store');
        Route::get('/team/{user}/edit', [TeamController::class, 'edit'])->name('team.edit');
        Route::put('/team/{user}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('/team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');
    });
});
