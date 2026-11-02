<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA entrypoint
|--------------------------------------------------------------------------
|
| Every GET that is not owned by the backend (API, Filament admin, Echo
| auth, Sanctum, Livewire/Filament assets, health check) serves the Vue
| shell; Vue Router resolves the path on the client. Deep paths use the
| fallback route so any backend route registered later still wins.
|
*/

Route::view('/', 'app')->name('spa');

Route::fallback(fn () => view('app'))
    ->where('fallbackPlaceholder', '^(?!(api|admin|broadcasting|sanctum|livewire|filament|storage|build|up)(/|$)).*$');
