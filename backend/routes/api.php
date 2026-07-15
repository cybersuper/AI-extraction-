<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IngestionController;
use Illuminate\Support\Facades\Http;

Route::get('/test-ai-connection', function () {
    $apiKey = env('GEMINI_API_KEY');
    if (!$apiKey) {
        return response()->json(['error' => 'No API key set in .env'], 500);
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent";
    
    $payload = [
        'contents' => [
            ['parts' => [['text' => 'Hello! respond with only the word SUCCESS.']]]
        ]
    ];

    // Build options using native PHP stream context instead of cURL
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n" .
                         "x-goog-api-key: " . $apiKey . "\r\n",
            'content' => json_encode($payload),
            'timeout' => 15, // 15-second timeout window
            'ignore_errors' => true // Captures the exact response code even if it's an error
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ];

    try {
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        // Capture response headers to grab the true HTTP status code
        $statusCode = 200;
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('{HTTP\/\S*\s(\d+)}', $http_response_header[0], $matches);
            $statusCode = intval($matches[1] ?? 200);
        }

        return response()->json([
            'status' => $statusCode,
            'body' => json_decode($result, true) ?? $result
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Stream connection completely failed!',
            'details' => $e->getMessage()
        ], 500);
    }
});

Route::post('/ingest-pdf', [IngestionController::class, 'upload']);