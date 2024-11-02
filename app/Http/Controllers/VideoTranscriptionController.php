<?php

namespace App\Http\Controllers;

use App\Models\Transcription; // Ensure you have the Transcription model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoTranscriptionController extends Controller
{
    public function showTranscribeForm()
    {
        $transcriptions = Transcription::all(); // Fetch all transcriptions
        return view('transcribe', compact('transcriptions')); // Pass them to the view
    }

    public function transcribe(Request $request)
    {
        $request->validate([
            'video_url' => 'required|url',
        ]);

        $videoUrl = $request->input('video_url');

        // Create a filename based on the video URL
        $filename = Str::slug(parse_url($videoUrl, PHP_URL_PATH)) . '_' . time() . '.mp3';
        $outputFile = storage_path("app/public/audio/$filename");

        // Execute yt-dlp to download the audio
        $command = "yt-dlp -x --audio-format mp3 -o \"$outputFile\" \"$videoUrl\"";
        exec($command, $output, $returnVar);

        // Check if the audio download was successful
        if ($returnVar !== 0) {
            return redirect()->back()->withErrors(['error' => 'Failed to download audio: ' . implode("\n", $output)]);
        }

        try {
            // Send the audio file to AssemblyAI and start transcription
            $assemblyResponse = $this->sendToAssemblyAI($outputFile);
            $uploadUrl = $assemblyResponse['upload_url']; // Get the upload URL
            $transcriptionResponse = $this->startTranscription($uploadUrl); // Start transcription

            // Store transcription details in the database
            $this->storeTranscription($request->video_url, $transcriptionResponse['id']);
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        // Clean up the audio file after processing
        @unlink($outputFile);

        return redirect()->back()->with('message', 'Video transcription started successfully.');
    }

    protected function sendToAssemblyAI($filePath)
    {
        // Check if the file exists before trying to upload
        if (!file_exists($filePath)) {
            throw new \Exception("File does not exist: $filePath");
        }

        // Read the file content
        $fileContent = file_get_contents($filePath);

        // Prepare and make the API request to AssemblyAI
        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Transfer-Encoding' => 'chunked',
            ])
            ->withBody($fileContent, 'application/octet-stream')
            ->post('https://api.assemblyai.com/v2/upload');

        // Check if the response is successful
        if (!$response->successful()) {
            Log::error('Failed to send audio to AssemblyAI: ', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('Failed to send audio to AssemblyAI: ' . $response->body());
        }

        return $response->json(); // This will contain the upload URL and other metadata
    }

    protected function startTranscription($uploadUrl)
    {
        // Prepare the request body with the correct parameters
        $requestBody = [
            'audio_url' => $uploadUrl,
            'punctuate' => true,
            'word_boost' => [], // Add any word boosting here if needed
        ];

        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.assemblyai.com/v2/transcript', $requestBody);

        if (!$response->successful()) {
            throw new \Exception('Failed to start transcription: ' . $response->body());
        }

        return $response->json();
    }

    protected function storeTranscription($videoUrl, $transcriptionId)
    {
        // Create a new transcription record
        $transcription = new Transcription();
        $transcription->video_url = $videoUrl;
        $transcription->transcription_id = $transcriptionId;
        $transcription->status = 'processing'; // Set initial status
        $transcription->text = json_encode(['transcription' => '']); // Initialize with empty JSON
        $transcription->save();
    }

    protected function checkTranscriptionStatus($transcriptionId)
    {
        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->get("https://api.assemblyai.com/v2/transcript/{$transcriptionId}");

        if (!$response->successful()) {
            throw new \Exception('Failed to check transcription status: ' . $response->body());
        }

        return $response->json();
    }

    public function updateTranscription($transcriptionId)
    {
        $transcription = Transcription::where('transcription_id', $transcriptionId)->first();

        if ($transcription) {
            $statusResponse = $this->checkTranscriptionStatus($transcriptionId);
            $transcription->status = $statusResponse['status'];

            // Check if transcription is completed
            if ($statusResponse['status'] === 'completed') {

                // Ensure the words key exists and is an array
                if (isset($statusResponse['words']) && is_array($statusResponse['words'])) {
                    // Save transcription text and timestamps as JSON
                    $transcription->text = json_encode([
                        'transcription' => $statusResponse['words'],
                    ]);
                    $transcription->status = 'completed'; // Update status
                }
            }

            $transcription->save();
        }
    }

    public function searchTimestamps(Request $request)
    {
        $request->validate([
            'search_word' => 'required|string|max:255',
        ]);

        $searchWord = strtolower($request->input('search_word')); // Make the search case-insensitive
        $transcriptions = Transcription::where('status', 'completed')->get();
        $results = [];

        foreach ($transcriptions as $transcription) {
            $transcriptionData = json_decode($transcription->text, true);

            if (isset($transcriptionData['transcription'])) {
                foreach ($transcriptionData['transcription'] as $wordData) {
                    // Check each word for a match (case-insensitive)
                    if (strtolower($wordData['text']) === $searchWord) {
                        // Convert milliseconds to minutes and seconds
                        $startTimeInSeconds = $wordData['start'] / 1000;
                        $minutes = floor($startTimeInSeconds / 60);
                        $seconds = $startTimeInSeconds % 60;

                        $results[] = [
                            'text' => $wordData['text'],
                            'start_time' => sprintf('%02d:%02d', $minutes, $seconds),
                            'confidence' => $wordData['confidence'],
                        ];
                    }
                }
            }
        }

        return view('transcribe', compact('results'));
    }
}
