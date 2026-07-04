<?php

declare(strict_types=1);

use App\Http\Controllers\PortalAccessController;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', fn (): Factory|\Illuminate\Contracts\View\View => view('home'))->name('home');

// Portal access (customer + vendor): signed-link set-password + forgot. The
// guard is fixed per route via defaults('guard', ...) — never from user input.
foreach (['customer' => 'portal', 'vendor' => 'vendor-portal'] as $portalGuard => $portalPath) {
    Route::prefix($portalPath)->name("portal.{$portalGuard}.")->group(function () use ($portalGuard): void {
        Route::get('set-password/{id}', [PortalAccessController::class, 'showSetPassword'])
            ->defaults('guard', $portalGuard)->middleware('signed')->name('set-password');
        Route::post('set-password/{id}', [PortalAccessController::class, 'setPassword'])
            ->defaults('guard', $portalGuard)->middleware('signed')->name('set-password.store');
        Route::get('forgot', [PortalAccessController::class, 'showForgot'])
            ->defaults('guard', $portalGuard)->name('forgot');
        Route::post('forgot', [PortalAccessController::class, 'sendForgot'])
            ->defaults('guard', $portalGuard)->middleware('throttle:6,1')->name('forgot.send');
    });
}
