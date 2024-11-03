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
        $transcriptions = Transcription::all();
        return response()->json(['transcriptions' => $transcriptions]);
    }

    public function transcribe(Request $request)
    {
        $request->validate([
            'video_url' => 'required|url',
        ]);

        $videoUrl = $request->input('video_url');

        $filename = Str::slug(parse_url($videoUrl, PHP_URL_PATH)) . '_' . time() . '.mp3';
        $outputFile = storage_path("app/public/audio/$filename");

        $command = "yt-dlp -x --audio-format mp3 -o \"$outputFile\" \"$videoUrl\"";
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            return response()->json(['error' => 'Failed to download audio: ' . implode("\n", $output)], 500);
        }

        try {
            // Send the audio file to AssemblyAI and start transcription
            $assemblyResponse = $this->sendToAssemblyAI($outputFile);
            $uploadUrl = $assemblyResponse['upload_url']; // Get the upload URL
            $transcriptionResponse = $this->startTranscription($uploadUrl); // Start transcription

            // Store transcription details in the database
            $this->storeTranscription($request->video_url, $transcriptionResponse['id']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        // Clean up the audio file after processing
        @unlink($outputFile);

        return response()->json(['message' => 'Video transcription started successfully.']);
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
        // Find the transcription by its ID
        $transcription = Transcription::where('transcription_id', $transcriptionId)->first();

        if ($transcription) {
            // Check the transcription status from an external service
            $statusResponse = $this->checkTranscriptionStatus($transcriptionId);
            $transcription->status = $statusResponse['status'];

            // Check if transcription is completed
            if ($statusResponse['status'] === 'completed') {
                // Ensure the 'words' key exists and is an array
                if (isset($statusResponse['words']) && is_array($statusResponse['words'])) {
                    // Save transcription text and timestamps as JSON
                    $transcription->text = json_encode([
                        'transcription' => $statusResponse['words'],
                    ]);
                    $transcription->status = 'completed'; // Update status
                }
            }

            // Save the transcription
            $transcription->save();

            // Generate a title for the transcription
            $titlePrompt = "Create a short title for the following video transcription:";
            $transcriptionText = implode(' ', array_column($statusResponse['words'], 'text'));

            // Send the title prompt to ChatGPT
            $finalTitlePrompt = $titlePrompt . " " . $transcriptionText;

            try {
                // Get the title response from ChatGPT
                $gptTitleResponse = $this->getGPTResponse($finalTitlePrompt);

                // Assuming the response is a single string
                $transcription->title = $gptTitleResponse;
                $transcription->save(); // Save the title in the database

                // Send the first prompt to extract key points from ChatGPT
                $keypointsPrompt = "Extract 5 key points with timestamps from the following video transcription. 
                The first key point is 'intro' and the last is 'conclusion'. LIMIT THE RESPONSE to be one word!!!";

                // Append the transcription text to the prompt
                $finalKeypointsPrompt = $keypointsPrompt . " " . $transcriptionText;

                // Send the prompt to ChatGPT to get key points
                $gptKeypointsResponse = $this->getGPTResponse($finalKeypointsPrompt);

                // Save the GPT key points response in the 'keypoints' column
                $transcription->keypoints = json_encode($gptKeypointsResponse);
                $transcription->save();

                // Send the second prompt to summarize the transcription
                $summaryPrompt = "Summarize the following video transcription in a maximum of 100 words:";
                $finalSummaryPrompt = $summaryPrompt . " " . $transcriptionText;

                // Send the second prompt to ChatGPT for summarization
                $gptSummaryResponse = $this->getGPTResponse($finalSummaryPrompt);

                // Save the GPT summary response in the 'summary' column
                $transcription->summary = json_encode($gptSummaryResponse);
                $transcription->save();
            } catch (\Exception $e) {
                // Handle the exception if ChatGPT API fails
                return response()->json(['error' => 'Failed to process transcription: ' . $e->getMessage()], 500);
            }
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
            $textArray = json_decode($transcription->text, true)['transcription'] ?? [];
            foreach ($textArray as $word) {
                if (strpos(strtolower($word['text']), $searchWord) !== false) {
                    $results[] = [
                        'transcription_id' => $transcription->id,
                        'video_url' => $transcription->video_url,
                        'timestamp' => $word['start'], // Assuming you want the start time of the word
                    ];
                }
            }
        }

        return response()->json(['results' => $results]);
    }

    protected function getGPTResponse($prompt)
    {
        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 100, // Limit to 100 tokens for title/summarization
            ]);

        if (!$response->successful()) {
            Log::error('Failed to get response from OpenAI API: ', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('Failed to get response from OpenAI API: ' . $response->body());
        }

        return $response->json()['choices'][0]['message']['content'];
    }
}
