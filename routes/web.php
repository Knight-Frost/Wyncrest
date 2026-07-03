<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Named 'login' route required by Laravel's Authenticate middleware.
 *
 * This is a JSON API with no server-rendered login page. When an UNAUTHENTICATED
 * request without an `Accept: application/json` header hits a guarded route (e.g.
 * a direct admin file-download link), the auth middleware computes a redirect to
 * route('login'); without this route that call throws and 500s. Defining it makes
 * such requests resolve to a clean 401. API requests (api/*) are already rendered
 * as JSON 401 by the exception handler and never reach this body.
 */
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');
