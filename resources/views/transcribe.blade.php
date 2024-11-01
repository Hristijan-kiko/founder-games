<!-- resources/views/transcribe.blade.php -->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Transcription</title>

</head>

<body>
    <div class="container">
        <h1>Video Transcription</h1>

        <form action="{{ url('/transcribe') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="video_url">Video URL:</label>
                <input type="url" name="video_url" id="video_url" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Transcribe Video</button>
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
    </div>


    <script>
        document.getElementById('transcriptionForm').addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent the default form submission

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
