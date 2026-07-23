<?php

use Illuminate\Support\Facades\Route;

Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return 'Database connected: ' . DB::connection()->getDatabaseName();
    } catch (\Exception $e) {
        return 'Connection failed: ' . $e->getMessage();
    }
});

Route::get('/', function () {
    return view('welcome');
});
