<?php

namespace App\Http\Controllers;

use App\Models\Transcription; // Ensure you have the Transcription model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoTranscriptionController extends Controller
{
    public function showTranscribeForm()
    {
        return view('transcribe');
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
        // @unlink($outputFile);

        return redirect()->back()->with('message', 'Video transcription started successfully.');
    }

    protected function sendToAssemblyAI($filePath)
    {
        // Check if the file exists before trying to upload
        if (!file_exists($filePath)) {
            throw new \Exception("File does not exist: $filePath");
        }

        // Open the audio file for reading
        $fileResource = fopen($filePath, 'r');

        // Prepare and make the API request to AssemblyAI
        $response = Http::withOptions(['verify' => false]) // Disable SSL verification
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->attach('file', $fileResource, basename($filePath))
            ->post('https://api.assemblyai.com/v2/upload');

        // Check if the response is successful
        if (!$response->successful()) {
            Log::error('Failed to send audio to AssemblyAI: ', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('Failed to send audio to AssemblyAI: ' . $response->body());
        }

        // Decode the JSON response from AssemblyAI
        $responseData = $response->json();

        // Log the response data to verify its structure
        Log::info('Response from AssemblyAI: ', $responseData);

        // Ensure the response is of a valid type
        if (!is_array($responseData) && !is_object($responseData)) {
            throw new \Exception('Unexpected response type: ' . gettype($responseData));
        }

        return $responseData; // This will contain the upload URL and other metadata
    }

    protected function startTranscription($uploadUrl)
    {
        // Make a request to start transcription with the uploaded audio URL
        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.assemblyai.com/v2/transcript', [
                'audio_url' => $uploadUrl, // Pass the upload URL to the request
                'language_model' => 'assemblyai_default', // Specify the language model if necessary
            ]);

        // Check if the transcription request was successful
        if (!$response->successful()) {
            Log::error('Failed to start transcription: ', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('Failed to start transcription: ' . $response->body());
        }

        return $response->json(); // This will return the transcription ID and other details
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

    protected function updateTranscription($transcriptionId)
    {
        $transcription = Transcription::where('transcription_id', $transcriptionId)->first();

        if ($transcription) {
            $statusResponse = $this->checkTranscriptionStatus($transcriptionId);
            $transcription->status = $statusResponse['status'];

            // Check if transcription is completed
            if ($statusResponse['status'] === 'completed') {
                // Save transcription text as JSON
                $transcription->text = json_encode(['transcription' => $statusResponse['text']]); // Store text as JSON
                $transcription->status = 'completed'; // Update status
            }

            $transcription->save();
        }
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
}
