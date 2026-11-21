<?php

use App\Http\Controllers\MarketplaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketplaceController::class, 'home'])->name('home');
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
    Route::post('/candidatura-criador', [MarketplaceController::class, 'applyCreator'])->name('creator.apply');
    Route::post('/criador/conteudos', [MarketplaceController::class, 'uploadMedia'])->name('creator.media.store');
    Route::post('/media/{slug}/claim', [MarketplaceController::class, 'claimFree'])->name('media.claim');
    Route::get('/biblioteca/{media}/download', [MarketplaceController::class, 'downloadLibrary'])->name('library.download');
});

Route::prefix('admin')->middleware(['auth','admin'])->group(function (): void {
    Route::get('/documentos/{document}', [MarketplaceController::class, 'verificationDocument'])->name('admin.document');
    Route::post('/criadores/{case}/decisao', [MarketplaceController::class, 'decideCreator'])->name('admin.creator.decide');
});
