<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Transcription</title>
    <!-- Include Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>

<body>
    <div class="container mt-5">
        <h1 class="text-center text-primary">Video Transcription</h1>

        <form action="{{ url('/transcribe') }}" method="POST" id="transcriptionForm" class="mt-4">
            @csrf
            <div class="form-group">
                <label for="video_url">Video URL:</label>
                <input type="url" name="video_url" id="video_url" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Transcribe Video</button>
        </form>

        @if (session('message'))
            <div class="alert alert-success mt-3">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger mt-3">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <h2 class="mt-5">All Transcriptions</h2>
        @if ($transcriptions->isEmpty())
            <p>No transcriptions available.</p>
        @else
            <table class="table table-bordered mt-3">
                <thead class="thead-dark">
                    <tr>
                        <th>Video URL</th>
                        <th>Transcription ID</th>
                        <th>Status</th>
                        <th>Transcription Text</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transcriptions as $transcription)
                        <tr>
                            <td>{{ $transcription->video_url }}</td>
                            <td>{{ $transcription->transcription_id }}</td>
                            <td>{{ $transcription->status }}</td>
                            <td>{{ json_decode($transcription->text)->transcription ?? 'N/A' }}</td>
                            <td>{{ $transcription->created_at->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <script>
        document.getElementById('transcriptionForm').addEventListener('submit', function(event) {
            const formData = new FormData(this);
            const messageDiv = document.getElementById('message');
            messageDiv.innerHTML = ''; // Clear previous messages
            messageDiv.className = ''; // Clear any previous classes

            // Display loading message
            messageDiv.innerHTML = '<p>Submitting your request...</p>';
            messageDiv.classList.add('alert', 'alert-info');

            // Use fetch API to submit the form data
            fetch(this.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest', // Indicate that it's an AJAX request
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        messageDiv.innerHTML = `<p>${data.error}</p>`;
                        messageDiv.classList.add('alert', 'alert-danger');
                    } else {
                        messageDiv.innerHTML = `<p>${data.message}</p>`;
                        messageDiv.classList.add('alert', 'alert-success');
                        // Optionally, you could refresh the page or update the transcriptions section
                    }
                })
                .catch(error => {
                    messageDiv.innerHTML = `<p>Something went wrong. Please try again later.</p>`;
                    messageDiv.classList.add('alert', 'alert-danger');
                });
        });
    </script>
</body>

</html>
