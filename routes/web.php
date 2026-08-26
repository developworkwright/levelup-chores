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
    // Home: the day laid out in the order it should be done in — quest, chest,
    // spin, standings. The Arena held this slot and the news on it is still the
    // best news in the app, but a landing page has to answer "what do I do now"
    // before it answers "what is happening", and a kid who couldn't work out
    // where to go next is the whole reason this page exists.
    //
    // The bonus wheel has no route of its own any more: it *is* step three, so
    // sending a kid to another page to take the spin was a tab switch between
    // two halves of the same instruction.
    Route::redirect('/', '/kid/home');
    Volt::route('/home', 'kid.home')->name('kid.home');
    Volt::route('/arena', 'kid.arena')->name('kid.arena');
    Volt::route('/quests', 'kid.quests')->name('kid.quests');
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
    // Its own screen rather than a third section on the Loot Shop admin: the
    // Lucky Block's odds are flat, so the prize list *is* the balance, and a
    // screen that leads with that reads differently from a shelf of prices.
    Volt::route('/lucky', 'parent.lucky')->name('parent.lucky');
    Volt::route('/monsters', 'parent.monsters')->name('parent.monsters');
    Volt::route('/kids', 'parent.kids')->name('parent.kids');
    Volt::route('/standings', 'parent.standings')->name('parent.standings');
    Volt::route('/activity', 'parent.activity')->name('parent.activity');
});
