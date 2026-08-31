<?php

use App\Http\Controllers\HealthController;

Route::get('/health', [HealthController::class, 'index']);

