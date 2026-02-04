<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\MetaOAuthController;
use App\Http\Controllers\MetaSdkController;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->to('dashboard');
    }

    return redirect()->to('dashboard/login');
})->name('home');


Route::middleware(['auth'])->group(function () {
    Route::prefix('adm')->name('admin.')->group(function () {
        Route::get('usuarios', [UserController::class, 'index'])->name('users.index');
        Route::post('usuarios', [UserController::class, 'store'])->name('users.store');
        Route::put('usuarios/{user}', [UserController::class, 'update'])->name('users.update');
    });

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    Route::get('meta/connect', [MetaOAuthController::class, 'redirect'])->name('meta.connect');
    Route::get('meta/callback', [MetaOAuthController::class, 'callback'])->name('meta.callback');
    Route::post('meta/disconnect', [MetaOAuthController::class, 'disconnect'])->name('meta.disconnect');
    Route::post('meta/sdk-token', [MetaSdkController::class, 'storeToken'])->name('meta.sdk-token');
});
