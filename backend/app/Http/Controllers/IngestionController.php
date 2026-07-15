<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class IngestionController extends Controller
{
    public function upload(Request $request)
    {
        set_time_limit(120); // Prevents PHP from timing out at 30/60s default
        $validator = Validator::make($request->all(), [
            'pdf' => 'required|mimes:pdf|max:15000', 
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or missing PDF file.',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $file = $request->file('pdf');
            $filePath = $file->getRealPath();
            $apiKey = env('GEMINI_API_KEY');

            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gemini API key is missing.'
                ], 500);
            }

            // ==========================================
            // STEP 1: UPLOAD RAW BINARY TO GOOGLE FILES
            // ==========================================
            $uploadUrl = "https://generativelanguage.googleapis.com/upload/v1beta/files?uploadType=media&key=" . $apiKey;
            
            $uploadResponse = Http::withoutVerifying()
                ->withHeaders(['Content-Type' => 'application/pdf'])
                ->withBody(file_get_contents($filePath), 'application/pdf')
                ->timeout(30)
                ->post($uploadUrl);

            if ($uploadResponse->failed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed uploading PDF to Gemini Files API.',
                    'details' => $uploadResponse->body()
                ], 502);
            }

            $uploadedFileMetadata = $uploadResponse->json();
            $fileUri = $uploadedFileMetadata['file']['uri'] ?? null;
            $fileName = $uploadedFileMetadata['file']['name'] ?? null;

            if (!$fileUri || !$fileName) {
                return response()->json([
                    'success' => false, 
                    'message' => 'No file URI received from Google.'
                ], 502);
            }

            // ==========================================
            // STEP 2: WAIT UNTIL THE FILE STATE IS "ACTIVE"
            // ==========================================
            $statusUrl = "https://generativelanguage.googleapis.com/v1beta/{$fileName}?key=" . $apiKey;
            $maxRetries = 10;
            $isReady = false;

            for ($i = 0; $i < $maxRetries; $i++) {
                $statusResponse = Http::withoutVerifying()->timeout(10)->get($statusUrl);
                
                if ($statusResponse->successful()) {
                    $statusData = $statusResponse->json();
                    $state = $statusData['state'] ?? 'PROCESSING';

                    if ($state === 'ACTIVE') {
                        $isReady = true;
                        break;
                    }

                    if ($state === 'FAILED') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Google failed to process the uploaded PDF file.'
                        ], 502);
                    }
                }
                
                usleep(500000); // 500ms
            }

            if (!$isReady) {
                return response()->json([
                    'success' => false,
                    'message' => 'Timeout waiting for file to become active on Google cloud.'
                ], 504);
            }

            // ==========================================
            // STEP 3: RUN INFERENCE WITH GEMINI
            // ==========================================
            $promptText = "You are an industrial parser. Analyze this uploaded PDF (Rapport de Dépilage). 
            Extract the data and return a JSON object that strictly adheres to this structure:
            {
              \"productionDate\": \"YYYY-MM-DD format\",
              \"productionUnit\": \"The unit name\",
              \"shift\": \"Matin, Soir, or Nuit\",
              \"departEmb\": integer,
              \"resteEmb\": integer,
              \"deuxiemeEmb\": integer,
              \"stockWagonsInitial\": integer,
              \"stockWagonsFinal\": integer,
              \"initialPackets\": integer,
              \"finalPackets\": integer,
              \"notes\": \"comments\",
              \"wagons\": [
                {
                  \"line\": integer,
                  \"wagonNumber\": \"string\",
                  \"produitCode\": \"string\",
                  \"packedSymbol\": \"string\",
                  \"packedQuantity\": integer,
                  \"bulkSymbol\": \"string\",
                  \"bulkQuantity\": integer,
                  \"stopReason\": \"string\",
                  \"stopDuration\": \"string\"
                }
              ]
            }
            Do not output markdown code blocks. Return ONLY the raw JSON.";

$inferenceUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=" . $apiKey;            
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $promptText],
                            ['fileData' => ['mimeType' => 'application/pdf', 'fileUri' => $fileUri]]
                        ]
                    ]
                ]
            ];

$inferenceResponse = Http::withoutVerifying()
                ->timeout(120) // Raised from 30 to 120 seconds
                ->post($inferenceUrl, $payload);

            if ($inferenceResponse->failed()) {
                return response()->json([
                    'success' => false, 
                    'message' => 'Google Inference Server did not respond correctly.',
                    'details' => $inferenceResponse->json() ?? $inferenceResponse->body()
                ], 502);
            }

            $responseBody = $inferenceResponse->json();
            $responseText = $responseBody['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            
            // Clean dynamic response formats
            $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($responseText));
            $parsedData = json_decode($cleanJson, true) ?? [];

            // ==========================================
            // STEP 4: CLEANUP FILE FROM STORAGE
            // ==========================================
            Http::withoutVerifying()->delete($fileUri . "?key=" . $apiKey);

            return response()->json([
                'success' => true,
                'data' => $parsedData
            ]);

        } catch (\Exception $e) {
            Log::error('Ingestion error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing document.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}