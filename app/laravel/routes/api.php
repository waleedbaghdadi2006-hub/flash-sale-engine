<?php

Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now(),
        'services' => [
            'database' => 'checking',
            'redis' => 'checking',
        ]
    ]);
});