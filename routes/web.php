<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'login')->name('login');
Volt::route('/pin/{profile}', 'pin-entry')->name('pin');

// Dynamic so the installed PWA's name always matches config('app.name')
// (APP_NAME) instead of a value baked into a static public/ file.
Route::get('/manifest.webmanifest', function () {
    return response()->json([
        'name' => config('app.name'),
        'short_name' => config('app.name'),
        'description' => 'Clear your daily quest, stack points, cash out for loot.',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'portrait-primary',
        'background_color' => '#0a0512',
        'theme_color' => '#0a0512',
        'icons' => [
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ])->header('Content-Type', 'application/manifest+json');
})->name('manifest');

Route::post('/logout', function () {
    Auth::guard('profile')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->middleware('auth:profile')->name('logout');

Route::middleware(['auth:profile', 'role:kid', 'sync-streak'])->prefix('kid')->group(function () {
    // The Arena, not Quests. It is the one kid page that is not about the kid
    // looking at it, which is exactly why it is the one they should land on:
    // whose run is on the line tonight is news, and their own board is one tap
    // away from it.
    Route::redirect('/', '/kid/arena');
    Volt::route('/arena', 'kid.arena')->name('kid.arena');
    Volt::route('/quests', 'kid.quests')->name('kid.quests');
    Volt::route('/wheel', 'kid.wheel')->name('kid.wheel');
    Volt::route('/loot', 'kid.loot')->name('kid.loot');
    Volt::route('/goal', 'kid.goal')->name('kid.goal');
    // Swaps and jobs on one page: they were only ever one idea, split by who
    // the deal was aimed at rather than by what kind of deal it was.
    Volt::route('/trades', 'kid.trades')->name('kid.trades');
    Volt::route('/bonus', 'kid.bonus')->name('kid.bonus');
    Volt::route('/badges', 'kid.badges')->name('kid.badges');
    Volt::route('/stats', 'kid.stats')->name('kid.stats');
    Volt::route('/journal', 'kid.journal')->name('kid.journal');
});

Route::middleware(['auth:profile', 'role:parent'])->prefix('parent')->group(function () {
    Route::redirect('/', '/parent/approvals');
    Volt::route('/approvals', 'parent.approvals')->name('parent.approvals');
    Volt::route('/chores', 'parent.chores')->name('parent.chores');
    Volt::route('/loot', 'parent.loot')->name('parent.loot');
    Volt::route('/monsters', 'parent.monsters')->name('parent.monsters');
    Volt::route('/kids', 'parent.kids')->name('parent.kids');
    Volt::route('/standings', 'parent.standings')->name('parent.standings');
    Volt::route('/activity', 'parent.activity')->name('parent.activity');
});
