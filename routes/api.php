<?php

use App\Http\Controllers\NationalizeController;
use Illuminate\Support\Facades\Route;

// No /api prefix (see bootstrap/app.php).
Route::post('/nationalize', NationalizeController::class);
