<!DOCTYPE html>
<html>
<head>
    <title>Send Slack Message</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h2>Send Message to Slack</h2>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form action="{{ route('slack.send') }}" method="POST">
        @csrf
        <div class="mb-3">
            <label for="message" class="form-label">Message</label>
            <textarea name="message" id="message" class="form-control" rows="4" placeholder="Type your message here...">{{ old('message') }}</textarea>
            @error('message')
            <span class="text-danger">{{ $message }}</span>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary">Send to Slack</button>
    </form>
</div>
</body>
</html>
