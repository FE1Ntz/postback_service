<?php

use App\Http\Controllers\PostbackController;
use App\Http\Middleware\EnsureInspectionToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/postback', [PostbackController::class, 'store'])
    ->middleware('cache.headers:no_store;private')
    ->name('postbacks.store');

// Laravel automatically adds HEAD to GET routes; probes must not ingest events.
Route::match(['HEAD'], '/postback', fn () => response('', 405, ['Allow' => 'GET']))
    ->name('postbacks.head');

Route::get('/events', [PostbackController::class, 'index'])
    ->middleware([EnsureInspectionToken::class, 'cache.headers:no_store;private'])
    ->name('postbacks.index');

Route::view('/docs', 'docs')->name('api.docs');
Route::get('/openapi.json', fn () => response(File::get(resource_path('openapi.json')), 200, [
    'Content-Type' => 'application/json',
]))->name('api.schema');
