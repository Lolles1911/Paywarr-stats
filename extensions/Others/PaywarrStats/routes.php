<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\PaywarrStats\PaywarrStats;

// Loaded from PaywarrStats::boot(). The customer's own statistics.
Route::middleware(['web', 'auth'])->get('/stats', [PaywarrStats::class, 'page'])->name('extensions.paywarr-stats');

// A poster from the customer's own "Recently watched", fetched from Tracearr
// (which may only be reachable inside your network). By number, never by URL.
Route::middleware(['web', 'auth', 'throttle:120,1'])->get('/stats/poster/{number}', [PaywarrStats::class, 'poster'])
    ->whereNumber('number')
    ->name('extensions.paywarr-stats.poster');

// The administrators' overview (PaywarrStats::isAdmin() checks the permission).
Route::middleware(['web', 'auth'])->get('/stats/admin', [PaywarrStats::class, 'adminPage'])->name('extensions.paywarr-stats.admin');
Route::middleware(['web', 'auth', 'throttle:240,1'])->get('/stats/admin/poster/{number}', [PaywarrStats::class, 'adminPoster'])
    ->whereNumber('number')
    ->name('extensions.paywarr-stats.admin.poster');
