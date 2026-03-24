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

            // Slack retry check
            $retryCount = $request->header('X-Slack-Retry-Num');
            if ($retryCount !== null && $retryCount > 0) {

                return response()->json(['success' => true, 'message' => 'Retry ignored']);
            }

//            if (isset($payload['event']) && !isset($payload['event']['subtype'])) {
            if (isset($payload['event'])) {

                $eventId = $payload['event_id'] ?? null;
                $eventTime = $payload['event_time'] ?? null;
                $userId = $payload['event']['user'] ?? null;
                $originalText = $payload['event']['text'] ?? '';

                if (!$userId) {
                    return response()->json(['success'=>true]);
                }

                $attachment = '';
                if (isset($payload['event']['files']) && count($payload['event']['files']) > 0) {
                    $urls = [];
                    foreach ($payload['event']['files'] as $file) {
                        $urls[] = $file['url_private'] ?? '';
                    }
                    $attachment = implode("\n" , $urls);
                }

                // Duplicate check
                $uniqueKey = "slack_event_{$eventId}_{$userId}_{$eventTime}";
                if (Cache::has($uniqueKey)) {
                    Log::info("Duplicate event detected, skipping", ['event_id' => $eventId]);
                    return response()->json(['success' => true]);
                }
                Cache::put($uniqueKey, true, now()->addMinutes(5));

                // User info
                $userData = $this->getSlackUserInfo($userId);
                $name = $userData['name'];
                $email = $userData['email'];
                $time = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');

                $textToSave = $this->convertMentionsToNames($originalText);

                $type = 'MESSAGE';

                // Save
                $this->saveToSheet($name, $email, $type, $textToSave, $attachment, $time);

                Log::info("Message detected", [
                    'name' => $name,
                    'email' => $email,
                    'message' => $textToSave,
                    'time' => $time,
                    'event_id' => $eventId
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Slack attendance error", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json(['success'=>true]);
    }

    private function saveToSheet($name, $email, $type, $message, $attachment, $time)
    {
        try {
            Log::info("Connecting to Google Sheets");

            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(Sheets::SPREADSHEETS);

            $service = new Sheets($client);
            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";

            if ($this->isDuplicateInSheet($service, $spreadsheetId, $name, $email, $type, $message, $time)) {
                Log::info("Duplicate entry detected in sheet, skipping append");
                return;
            }

            $values = [[$name, $email, $type, $message,$attachment, $time]];
            $body = new \Google\Service\Sheets\ValueRange(['values'=>$values]);
            $params = ['valueInputOption'=>'RAW'];

            $service->spreadsheets_values->append(
                $spreadsheetId,
                'Sheet1!A:F',
                $body,
                $params
            );

            Log::info("Data successfully appended to Google Sheet");
        } catch (\Exception $e) {
            Log::error("Google Sheet write failed", ['error' => $e->getMessage()]);
        }
    }

    private function isDuplicateInSheet($service, $spreadsheetId, $name, $email, $type, $message, $time)
    {
        try {
            $response = $service->spreadsheets_values->get($spreadsheetId, 'Sheet1!A:F');
            $rows = $response->getValues();
            if (empty($rows)) return false;

            $recentRows = array_slice($rows, -10);
            $newTime = \Carbon\Carbon::parse($time);

            foreach ($recentRows as $row) {
                if (count($row) >= 6) {
                    $rowTime = \Carbon\Carbon::parse($row[5]);
                    if ($row[0] == $name && $row[1] == $email && $row[2] == $type &&
                        $row[3] == $message && abs($newTime->diffInSeconds($rowTime)) < 30) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Error checking duplicates in sheet", ['error' => $e->getMessage()]);
        }
        return false;
    }
    private function convertMentionsToNames($text)
    {
        preg_match_all('/<@([A-Z0-9]+)>/', $text, $matches);

        foreach ($matches[1] as $mentionedUserId) {
            $mentionedUser = $this->getSlackUserInfo($mentionedUserId);
            $mentionedName = $mentionedUser['name'] ?? 'Unknown';
            $text = str_replace("<@{$mentionedUserId}>", "@{$mentionedName}", $text);
        }

        return $text;
    }

    private function getSlackUserInfo($userId)
    {
        $cacheKey = "slack_user_{$userId}";
        if (Cache::has($cacheKey)) return Cache::get($cacheKey);

        $token = env('SLACK_BOT_TOKEN');

        $response = file_get_contents(
            "https://slack.com/api/users.info?user=".$userId,
            false,
            stream_context_create(["http"=>["header"=>"Authorization: Bearer ".$token]])
        );

        $data = json_decode($response,true);
        $userData = ['name'=>'Unknown','email'=>'Unknown'];

        if(isset($data['user'])) {
            $userData = [
                'name'=>$data['user']['profile']['real_name'] ?? 'Unknown',
                'email'=>$data['user']['profile']['email'] ?? 'Unknown'
            ];
            Cache::put($cacheKey, $userData, now()->addHour());
        }

        return $userData;
    }

// Show form
    public function showMessageForm()
    {
        return view('slack-message');
    }

    public function sendMessageFromForm(Request $request)
    {
        $request->validate([
            'message' => 'required|string'
        ]);

        $message = $request->message;
        $projectUser = auth()->user();

        // 1️⃣ Send message to Slack
        $slackUser = $this->getSlackUserByEmail($projectUser->email);
        $slackName = $slackUser['name'] ?? $projectUser->name;

        $this->sendMessageToSlack($message, $slackName);

        // 2️⃣ Save to Google Sheet with Slack name
        $type = 'MESSAGE';
        $time = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');
        $attachment = '';

        $this->saveToSheet($slackName, $projectUser->email, $type,$message, $attachment,$time);

        return back()->with('success', 'Message sent to Slack and Sheet!');
    }
    public function sendMessageToSlack($message, $username)
    {
        $token = env('SLACK_BOT_TOKEN');
        $channel = env('SLACK_CHANNEL_ID');

        $payload = [
            'channel' => $channel,
            'text' => $message,
            'username' => $username,
            'icon_emoji' => ':bust_in_silhouette:'
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

        return $response;
    }
// Helper: Get Slack user info by email
    private function getSlackUserByEmail($email)
    {
        $cacheKey = "slack_user_email_{$email}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $token = env('SLACK_BOT_TOKEN');
        $response = file_get_contents(
            "https://slack.com/api/users.lookupByEmail?email={$email}",
            false,
            stream_context_create([
                "http"=>[
                    "header"=>"Authorization: Bearer {$token}"
                ]
            ])
        );

        $data = json_decode($response, true);

        $userData = ['id' => null, 'name' => 'Unknown'];
        if (isset($data['user'])) {
            $userData = [
                'id' => $data['user']['id'],
                'name' => $data['user']['profile']['real_name'] ?? 'Unknown'
            ];
            Cache::put($cacheKey, $userData, now()->addHour());
        }

        return $userData;
    }}
