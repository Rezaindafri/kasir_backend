<?php

use Illuminate\Support\Facades\Route;

// Defense in depth when a request is routed through Laravel.
Route::get('/storage/proofs/{path?}', fn () => abort(403))
    ->where('path', '.*');

Route::get('/login', function () {
    return response()->json([
        'status' => 'error',
        'message' => 'Unauthenticated',
    ], 401);
})->name('login');

Route::get('/', function () {
    return view('welcome');
});
