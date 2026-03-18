<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Google\Client;
use Google\Service\Sheets;

class SlackController extends Controller
{

    public function attendance(Request $request)
    {
        Log::info("Slack request received", $request->all());

        $payload = $request->all();

        try {
            if (isset($payload['type']) && $payload['type'] === 'url_verification') {
                return response($payload['challenge'], 200)
                    ->header('Content-Type', 'text/plain');
            }

            // Slack retry headers
            $retryCount = $request->header('X-Slack-Retry-Num');
            $retryReason = $request->header('X-Slack-Retry-Reason');

            if ($retryCount !== null && $retryCount > 0) {
                Log::info("Slack retry detected", [
                    'retry_count' => $retryCount,
                    'retry_reason' => $retryReason
                ]);
                return response()->json(['success' => true, 'message' => 'Retry ignored']);
            }

            // Check event exists and not bot message
            if (isset($payload['event']) && !isset($payload['event']['subtype'])) {
                $eventId = $payload['event_id'] ?? null;
                $eventTime = $payload['event_time'] ?? null;
                $userId = $payload['event']['user'] ?? null;
                $text = strtolower($payload['event']['text'] ?? '');
                $text = str_replace([' ', '-'], '', $text);

                // Unique key to prevent duplicates
                $uniqueKey = "slack_event_{$eventId}_{$userId}_{$eventTime}";
                if (Cache::has($uniqueKey)) {
                    Log::info("Duplicate event detected, skipping", ['event_id' => $eventId]);
                    return response()->json(['success' => true, 'message' => 'Duplicate ignored']);
                }
                Cache::put($uniqueKey, true, now()->addMinutes(5));

                Log::info("Slack message text", ['text' => $text]);

                // Check attendance keywords
                $checkIn = str_contains($text, 'checkin');
                $checkOut = str_contains($text, 'checkout');

                // Only one keyword → attendance sheet
                if ($checkIn xor $checkOut) {
                    $type = $checkIn ? 'CHECK IN' : 'CHECK OUT';

                    $recentKey = "recent_{$userId}_{$type}";
                    $lastProcessed = Cache::get($recentKey);

                    if ($lastProcessed && (time() - $lastProcessed) < 30) {
                        Log::info("Skipping - too soon since last similar entry", [
                            'user_id' => $userId,
                            'type' => $type,
                            'seconds_since_last' => time() - $lastProcessed
                        ]);
                    } else {
                        Cache::put($recentKey, time(), now()->addMinutes(1));

                        $userData = $this->getSlackUserInfo($userId);
                        $name = $userData['name'];
                        $email = $userData['email'];
                        $time = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');

                        Log::info("Attendance detected", [
                            'name' => $name,
                            'email' => $email,
                            'type' => $type,
                            'time' => $time,
                            'event_id' => $eventId
                        ]);

                        $this->saveToSheet($name, $email, $type, $time);
                    }

                } else {
                    // All other messages → abuse sheet
                    $this->saveAbuseMessage($userId, $text, $eventTime ?? now());
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
    private function saveAbuseMessage($userId, $text, $time)
    {
        $userData = $this->getSlackUserInfo($userId);
        $name = $userData['name'];
        $email = $userData['email'];

        // Time in GMT+5
        $timeFormatted = \Carbon\Carbon::parse($time)->timezone('Asia/Karachi')->format('Y-m-d H:i:s');

        Log::info("Abuse/General message detected", [
            'name' => $name,
            'email' => $email,
            'text' => $text,
            'time' => $timeFormatted
        ]);

        // Optional: Save to separate Google Sheet
        try {
            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(Sheets::SPREADSHEETS);

            $service = new Sheets($client);
            $spreadsheetId = "1r8spn7LWA5247CPJpN5Ke9keJDwhvu44MS9SbitelvM";
//            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";


            $values = [[$name, $email, $text, $timeFormatted]];
            $body = new \Google\Service\Sheets\ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'RAW'];

            $service->spreadsheets_values->append(
                $spreadsheetId,
                'Sheet2!A:D',
                $body,
                $params
            );

            Log::info("Abuse/General message saved to Google Sheet");
        } catch (\Exception $e) {
            Log::error("Failed to save abuse message", ['error' => $e->getMessage()]);
        }
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


    private function isDuplicateInSheet($service, $spreadsheetId, array $columns, $time, $range)
    {
        try {
            // Fetch sheet values
            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $rows = $response->getValues();

            if (empty($rows)) {
                return false;
            }

            // Check last 10 rows
            $recentRows = array_slice($rows, -10);

            $newTime = \Carbon\Carbon::parse($time);

            foreach ($recentRows as $row) {
                $match = true;

                // Compare each column passed in $columns array
                foreach ($columns as $index => $value) {
                    if (!isset($row[$index]) || $row[$index] != $value) {
                        $match = false;
                        break;
                    }
                }

                if ($match) {
                    // Time check if last column exists
                    $rowTime = isset($row[count($columns)]) ? \Carbon\Carbon::parse($row[count($columns)]) : null;

                    if ($rowTime && abs($newTime->diffInSeconds($rowTime)) < 30) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error checking duplicates in sheet {$spreadsheetId}", ['error' => $e->getMessage()]);
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

//    public function fetchOldMessages()
//    {
//        ini_set('max_execution_time', 300);
//        $token = env('SLACK_BOT_TOKEN');
//        $channelId = env('SLACK_CHANNEL_ID');
//
//        $cacheKey = 'last_imported_message_ts';
//        $lastImportedTs = Cache::get($cacheKey, 0);
//
//        $url = "https://slack.com/api/conversations.history?channel=".$channelId."&limit=200";
//
//        $response = file_get_contents(
//            $url,
//            false,
//            stream_context_create([
//                "http"=>[
//                    "header"=>"Authorization: Bearer ".$token
//                ]
//            ])
//        );
//
//        $data = json_decode($response,true);
//
//        if(!isset($data['messages'])){
//            return "No messages found";
//        }
//
//        $newestTs = $lastImportedTs;
//        $importedCount = 0;
//
//        // Process messages from oldest to newest
//        foreach(array_reverse($data['messages']) as $message)
//        {
//            if(!isset($message['user']) || !isset($message['text'])){
//                continue;
//            }
//
//            $messageTs = (float)$message['ts']; // Convert to float for comparison
//
//            // Skip messages older than or equal to last import
//            if ($messageTs <= $lastImportedTs) {
//                continue;
//            }
//
//            $text = strtolower($message['text']);
//
//            if(str_contains($text,'check in') || str_contains($text,'check out'))
//            {
//                $type = str_contains($text,'check in') ? 'CHECK IN' : 'CHECK OUT';
//
//                $userData = $this->getSlackUserInfo($message['user']);
//
//                $name = $userData['name'];
//                $email = $userData['email'];
//
//                // Convert Slack timestamp (UTC) to GMT+5
//                $utcTime = \Carbon\Carbon::createFromTimestamp($message['ts']);
//                $gmt5Time = $utcTime->timezone('Asia/Karachi')->format('Y-m-d H:i:s');
//
//                $this->saveToSheet($name, $email, $type, $gmt5Time);
//
//                $importedCount++;
//
//                // Track the newest timestamp
//                if ($messageTs > $newestTs) {
//                    $newestTs = $messageTs;
//                }
//            }
//        }
//
//        // Update the last imported timestamp
//        if ($newestTs > $lastImportedTs) {
//            Cache::put($cacheKey, $newestTs, now()->addYear());
//        }
//
//        $gmt5Now = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');
//        return "Import complete: {$importedCount} new messages imported (GMT+5 as of {$gmt5Now})";
//    }
    public function fetchOldMessages()
    {
        ini_set('max_execution_time', 300);

        $token = env('SLACK_BOT_TOKEN');
        $channelId = env('SLACK_CHANNEL_ID');

        $cacheKey = 'last_imported_message_ts';
        $lastImportedTs = Cache::get($cacheKey, 0);

        $url = "https://slack.com/api/conversations.history?channel=".$channelId."&limit=200";

        $response = file_get_contents(
            $url,
            false,
            stream_context_create([
                "http"=>[
                    "header"=>"Authorization: Bearer ".$token
                ]
            ])
        );

        $data = json_decode($response,true);

        if(!isset($data['messages'])){
            return "No messages found";
        }

        $newestTs = $lastImportedTs;
        $importedCount = 0;

        // Process messages from oldest to newest
        foreach(array_reverse($data['messages']) as $message)
        {
            if(!isset($message['user']) || !isset($message['text'])){
                continue;
            }

            $messageTs = (float)$message['ts']; // Convert to float for comparison

            // Skip messages older than or equal to last import
            if ($messageTs <= $lastImportedTs) {
                continue;
            }

            $originalText = $message['text'];
            $text = strtolower($originalText);
            $text = str_replace([' ', '-'], '', $text); // Ignore spaces/hyphens

            $checkIn = str_contains($text, 'checkin');
            $checkOut = str_contains($text, 'checkout');

            if ($checkIn xor $checkOut) {
                // Attendance message
                $type = $checkIn ? 'CHECK IN' : 'CHECK OUT';

                $userData = $this->getSlackUserInfo($message['user']);
                $name = $userData['name'];
                $email = $userData['email'];

                // Convert Slack timestamp (UTC) to GMT+5
                $utcTime = \Carbon\Carbon::createFromTimestamp($message['ts']);
                $gmt5Time = $utcTime->timezone('Asia/Karachi')->format('Y-m-d H:i:s');

                $this->saveToSheet($name, $email, $type, $gmt5Time);

                $importedCount++;
            } else {
                // Abuse message
                $this->saveAbuseMessage($message['user'], $originalText, $messageTs ?? now());
            }

            // Track the newest timestamp
            if ($messageTs > $newestTs) {
                $newestTs = $messageTs;
            }
        }

        // Update the last imported timestamp
        if ($newestTs > $lastImportedTs) {
            Cache::put($cacheKey, $newestTs, now()->addYear());
        }

        $gmt5Now = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');
        return "Import complete: {$importedCount} new messages imported (GMT+5 as of {$gmt5Now})";
    }

    public function testMessage()
    {
        return $this->sendMessageToSlack("Hello from Laravel");
    }

    public function sendMessageToSlack($message)
    {
        $token = env('SLACK_BOT_TOKEN');
        $channel = env('SLACK_CHANNEL_ID');

        $payload = [
            'channel' => $channel,
            'text' => $message
        ];

        $ch = curl_init("https://slack.com/api/chat.postMessage");

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return "Message Sent To slack";
    }

}
