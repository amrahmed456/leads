<?php

use App\Http\Controllers\Auth\LoginController;
use App\Livewire\Businesses;
use App\Livewire\Campaigns;
use App\Livewire\Dashboard;
use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/', Dashboard::class)->name('dashboard');

    Route::livewire('/campaigns', Campaigns\Index::class)->name('campaigns.index');
    Route::livewire('/campaigns/create', Campaigns\Create::class)->name('campaigns.create');
    Route::livewire('/campaigns/{campaign}', Campaigns\Show::class)->whereNumber('campaign')->name('campaigns.show');

    Route::livewire('/businesses', Businesses\Index::class)->name('businesses.index');
    Route::livewire('/businesses/{business}', Businesses\Show::class)->whereNumber('business')->name('businesses.show');

    Route::livewire('/settings/sources', Settings\DataSources::class)->name('settings.sources');
    Route::livewire('/settings/sources/overture', Settings\OvertureImports::class)->name('settings.overture');
    Route::livewire('/settings/sources/alltheplaces', Settings\AtpSpiders::class)->name('settings.alltheplaces');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
