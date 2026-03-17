<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Google\Client;
use Google\Service\Sheets;

class SlackController extends Controller
{
    // Main attendance webhook
    public function attendance(Request $request)
    {
        Log::info("Slack request received", $request->all());

        $payload = $request->all();

        try {
            if (isset($payload['type']) && $payload['type'] === 'url_verification') {
                return response($payload['challenge'], 200)
                    ->header('Content-Type', 'text/plain');
            }
            // Check for Slack retry headers
            $retryCount = $request->header('X-Slack-Retry-Num');
            $retryReason = $request->header('X-Slack-Retry-Reason');

            if ($retryCount !== null) {
                Log::info("Slack retry detected", [
                    'retry_count' => $retryCount,
                    'retry_reason' => $retryReason
                ]);

                // If it's a retry, we might want to check if we already processed this
                if ($retryCount > 0) {
                    return response()->json(['success' => true, 'message' => 'Retry ignored']);
                }
            }

            // Check event exists and is not bot message
            if (isset($payload['event']) && !isset($payload['event']['subtype'])) {
                // Create a unique event ID to prevent duplicates
                $eventId = $payload['event_id'] ?? null;
                $eventTime = $payload['event_time'] ?? null;
                $userId = $payload['event']['user'] ?? null;
                $text = strtolower($payload['event']['text'] ?? '');

                // Create a unique key for this event
                $uniqueKey = "slack_event_{$eventId}_{$userId}_{$eventTime}";

                // Check if we've already processed this event (within last 5 minutes)
                if (Cache::has($uniqueKey)) {
                    Log::info("Duplicate event detected, skipping", ['event_id' => $eventId]);
                    return response()->json(['success' => true, 'message' => 'Duplicate ignored']);
                }

                // Store in cache for 5 minutes to prevent duplicates
                Cache::put($uniqueKey, true, now()->addMinutes(5));

                Log::info("Slack message text", ['text' => $text]);

                if (str_contains($text, 'check in') || str_contains($text, 'check out')) {
                    $type = str_contains($text, 'check in') ? 'CHECK IN' : 'CHECK OUT';

                    // Check for recent identical entries (within last 30 seconds)
                    $recentKey = "recent_{$userId}_{$type}";
                    $lastProcessed = Cache::get($recentKey);

                    if ($lastProcessed && (time() - $lastProcessed) < 30) {
                        Log::info("Skipping - too soon since last similar entry", [
                            'user_id' => $userId,
                            'type' => $type,
                            'seconds_since_last' => time() - $lastProcessed
                        ]);
                        return response()->json(['success' => true, 'message' => 'Rate limited']);
                    }

                    // Update last processed time
                    Cache::put($recentKey, time(), now()->addMinutes(1));

                    // Fetch proper user name & email from Slack API
                    $userData = $this->getSlackUserInfo($userId);

                    $name = $userData['name'];
                    $email = $userData['email'];

                    // Time in GMT+5
                    $time = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');

                    Log::info("Attendance detected", [
                        'name' => $name,
                        'email' => $email,
                        'type' => $type,
                        'time' => $time,
                        'event_id' => $eventId
                    ]);

                    // Append to Google Sheet
                    $this->saveToSheet($name, $email, $type, $time);
                } else {
                    Log::info("Message ignored - not attendance command");
                }
            } else {
                Log::info("No event key found or bot message ignored");
            }
        } catch (\Exception $e) {
            Log::error("Slack attendance error", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json(['success' => true]);
    }

    // Google Sheets append
    private function saveToSheet($name, $email, $type, $time)
    {
        try {
            Log::info("Connecting to Google Sheets");

            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(Sheets::SPREADSHEETS);

            $service = new Sheets($client);
            $spreadsheetId = "1r8spn7LWA5247CPJpN5Ke9keJDwhvu44MS9SbitelvM";
//            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";

            // Optional: Check for duplicates in the last 5 minutes before appending
            if ($this->isDuplicateInSheet($service, $spreadsheetId, $name, $email, $type, $time)) {
                Log::info("Duplicate entry detected in sheet, skipping append");
                return;
            }

            $values = [[$name, $email, $type, $time]];
            $body = new \Google\Service\Sheets\ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'RAW'];

            $service->spreadsheets_values->append(
                $spreadsheetId,
                'Sheet1!A:D',
                $body,
                $params
            );

            Log::info("Data successfully appended to Google Sheet");
        } catch (\Exception $e) {
            Log::error("Google Sheet write failed", ['error' => $e->getMessage()]);
        }
    }

    // Optional: Check for duplicates in the sheet
    private function isDuplicateInSheet($service, $spreadsheetId, $name, $email, $type, $time)
    {
        try {
            // Get recent entries (last 10 rows)
            $response = $service->spreadsheets_values->get($spreadsheetId, 'Sheet1!A:D');
            $rows = $response->getValues();

            if (empty($rows)) {
                return false;
            }

            // Get last 10 rows
            $recentRows = array_slice($rows, -10);

            // Parse the time to compare (ignoring seconds maybe)
            $newTime = \Carbon\Carbon::parse($time);

            foreach ($recentRows as $row) {
                if (count($row) >= 4) {
                    $rowTime = \Carbon\Carbon::parse($row[3]);

                    // If same name, email, type and within 30 seconds
                    if ($row[0] == $name &&
                        $row[1] == $email &&
                        $row[2] == $type &&
                        abs($newTime->diffInSeconds($rowTime)) < 30) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error checking duplicates in sheet", ['error' => $e->getMessage()]);
        }

        return false;
    }

    // Fetch Slack user info by ID
    private function getSlackUserInfo($userId)
    {
        // Add caching for user info to reduce API calls
        $cacheKey = "slack_user_{$userId}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $token = env('SLACK_BOT_TOKEN');

        $response = file_get_contents(
            "https://slack.com/api/users.info?user=" . $userId,
            false,
            stream_context_create([
                "http" => [
                    "header" => "Authorization: Bearer " . $token
                ]
            ])
        );

        $data = json_decode($response, true);

        $userData = ['name' => 'Unknown', 'email' => 'Unknown'];

        if (isset($data['user'])) {
            $userData = [
                'name' => $data['user']['profile']['real_name'] ?? 'Unknown',
                'email' => $data['user']['profile']['email'] ?? 'Unknown'
            ];

            // Cache for 1 hour
            Cache::put($cacheKey, $userData, now()->addHour());
        }

        return $userData;
    }

    public function fetchOldMessages()
    {
        ini_set('max_execution_time', 300);

        $token = env('SLACK_BOT_TOKEN');
        $channelId = env('SLACK_CHANNEL_ID');

        $url = "https://slack.com/api/conversations.history?channel=" . $channelId . "&limit=200";

        $response = file_get_contents(
            $url,
            false,
            stream_context_create([
                "http" => [
                    "header" => "Authorization: Bearer " . $token
                ]
            ])
        );

        $data = json_decode($response, true);

        if (!isset($data['messages'])) {
            return "No messages found";
        }

        foreach ($data['messages'] as $message) {
            if (!isset($message['user']) || !isset($message['text'])) {
                continue;
            }

            $text = strtolower($message['text']);

            if (str_contains($text, 'check in') || str_contains($text, 'check out')) {
                $type = str_contains($text, 'check in') ? 'CHECK IN' : 'CHECK OUT';

                $userData = $this->getSlackUserInfo($message['user']);

                $name = $userData['name'];
                $email = $userData['email'];

                $time = date('Y-m-d H:i:s', $message['ts']);

                $this->saveToSheet($name, $email, $type, $time);
            }
        }

        return "Old messages imported";
    }
}
