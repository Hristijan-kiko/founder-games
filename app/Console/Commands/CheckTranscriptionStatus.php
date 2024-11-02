<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use Illuminate\Console\Command;

class CheckTranscriptionStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-transcription-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get all transcriptions that are processing
        $transcriptions = Transcription::where('status', 'processing')->get();

        foreach ($transcriptions as $transcription) {
            // Call the update method from your controller
            $controller = new \App\Http\Controllers\VideoTranscriptionController();
            $controller->updateTranscription($transcription->transcription_id);
        }

        $this->info('Transcription statuses checked successfully.');
    }
}
