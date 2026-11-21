<?php

use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\CreatorPayoutController;
use App\Http\Controllers\AdminCreatorPayoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketplaceController::class, 'home'])->name('home');
Route::get('/criador/{slug}', [MarketplaceController::class, 'showCreator'])->name('creator.show');
Route::get('/media/{slug}', [MarketplaceController::class, 'showMedia'])->name('media.show');
Route::get('/media/{slug}/preview', fn (string $slug) => redirect('/api/media/'.$slug.'/preview'))->name('media.preview');

Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', [MarketplaceController::class, 'loginForm'])->name('login');
    Route::post('/entrar', [MarketplaceController::class, 'login'])->name('login.store');
    Route::get('/registar', [MarketplaceController::class, 'registerForm'])->name('register');
    Route::post('/registar', [MarketplaceController::class, 'register'])->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/sair', [MarketplaceController::class, 'logout'])->name('logout');
    Route::get('/minha-area', [MarketplaceController::class, 'dashboard'])->name('dashboard');
    Route::middleware('account:user')->group(function (): void {
        Route::get('/compras', [MarketplaceController::class, 'purchaseHistory'])->name('purchases.index');
        Route::get('/compras/{reference}', [MarketplaceController::class, 'purchaseDetail'])->name('purchases.show');
        Route::post('/media/{slug}/claim', [MarketplaceController::class, 'claimFree'])->name('media.claim');
        Route::post('/media/{slug}/checkout', [MarketplaceController::class, 'checkout'])->name('media.checkout');
        Route::get('/checkout/paypal/return', [MarketplaceController::class, 'paypalReturn'])->name('paypal.return');
        Route::get('/checkout/paypal/cancel', [MarketplaceController::class, 'paypalCancel'])->name('paypal.cancel');
        Route::get('/biblioteca/{media}/download', [MarketplaceController::class, 'downloadLibrary'])->name('library.download');
    });

    Route::middleware('account:creator')->group(function (): void {
        Route::post('/candidatura-criador', [MarketplaceController::class, 'applyCreator'])->name('creator.apply');
        Route::post('/criador/perfil', [MarketplaceController::class, 'updateCreatorProfile'])->name('creator.profile.update');
        Route::post('/criador/pagamentos', [CreatorPayoutController::class, 'store'])->name('creator.payouts.store')->middleware('throttle:3,60');
        Route::post('/criador/conteudos', [MarketplaceController::class, 'uploadMedia'])->name('creator.media.store');
        Route::post('/criador/conteudos/{media}/oferta', [MarketplaceController::class, 'updateMediaOffer'])->name('creator.media.offer');
        Route::post('/criador/conteudos/{media}/publicar', [MarketplaceController::class, 'publishMedia'])->name('creator.media.publish');
        Route::post('/criador/conteudos/{media}/retirar', [MarketplaceController::class, 'unpublishMedia'])->name('creator.media.unpublish');
    });
});

Route::prefix('admin')->middleware(['auth','admin'])->group(function (): void {
    Route::get('/documentos/{document}', [MarketplaceController::class, 'verificationDocument'])->name('admin.document');
    Route::post('/criadores/{case}/decisao', [MarketplaceController::class, 'decideCreator'])->name('admin.creator.decide');
    Route::post('/pagamentos/{payout}/enviar', [AdminCreatorPayoutController::class, 'submit'])->name('admin.payouts.submit')->middleware('throttle:5,1');
    Route::post('/pagamentos/{payout}/reconciliar', [AdminCreatorPayoutController::class, 'reconcile'])->name('admin.payouts.reconcile')->middleware('throttle:10,1');
});
