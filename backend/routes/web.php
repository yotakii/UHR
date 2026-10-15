<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'UHR API', 'health' => url('/api/health')]));
