<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Google\Client;
use Google\Service\Sheets;


//All Apis Uses
//for fetch abusive Message
//https://neutrinoapi.net/bad-word-filter


class SlackController extends Controller
{

    public function attendance(Request $request)
    {
//        Log::info("Slack request received", $request->all());

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

            if (!isset($payload['event'])) {
                return response()->json(['success' => true]);
            }

            $eventId = $payload['event_id'] ?? null;
            $eventTime = $payload['event_time'] ?? null;
            $userId = $payload['event']['user'] ?? null;
            $originalText = $payload['event']['text'] ?? '';

            if (!$userId) {
                return response()->json(['success' => true]);
            }

            // attachments
            $attachment = '';
            if (isset($payload['event']['files']) && count($payload['event']['files']) > 0) {
                $urls = [];
                foreach ($payload['event']['files'] as $file) {
                    $urls[] = $file['url_private'] ?? '';
                }
                $attachment = implode("\n", $urls);
            }

            // duplicate check
            $uniqueKey = "slack_event_{$eventId}_{$userId}_{$eventTime}";
            if (Cache::has($uniqueKey)) {
                Log::info("Duplicate event detected", ['event_id' => $eventId]);
                return response()->json(['success' => true]);
            }
            Cache::put($uniqueKey, true, now()->addMinutes(5));

            $userData = $this->getSlackUserInfo($userId);
            $name = $userData['name'];
            $email = $userData['email'];

            $time = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');
            $isManager = ($email === env('MANAGER_EMAIL'));

            /*
            -------------------------------------------------
            🚀 TASK FEATURE (RUN FIRST - RAW TEXT)
            -------------------------------------------------
            */

            $isTaskAssign = str_contains(strtolower($originalText), '#taskassignto');

            if ($isTaskAssign) {

                if (!$isManager) {

                    Log::warning("🚫 Task Assign Blocked (Unauthorized)", [
                        'user_email' => $email,
                        'user_name'  => $name,
                        'text'       => $originalText,
                        'time'       => $time,
                    ]);

                    return response()->json(['success' => true]);
                }

                preg_match('/<@([A-Z0-9]+)>/', $originalText, $match);
                $taskUserId = $match[1] ?? null;

                if ($taskUserId) {

                    $taskUser = $this->getSlackUserInfo($taskUserId);
                    $taskUserName = $taskUser['name'] ?? 'Unknown';

                    $taskText = preg_replace('/#taskassignto\s*/i', '', $originalText);
                    $taskText = str_replace("<@{$taskUserId}>", '', $taskText);
                    $taskText = $this->convertMentionsToNames($taskText);
                    $taskText = trim($taskText);

                    $this->saveTaskToSheet($taskUserName, $taskText, $time);

                    Log::info("✅ Task Assigned Successfully", [
                        'assigned_by' => $email,
                        'assigned_to' => $taskUserName,
                        'task'        => $taskText,
                        'time'        => $time,
                    ]);

                    return response()->json(['success' => true]);
                }
            }

            $isTaskUpdate = str_contains(strtolower($originalText), '#taskupdate:');

            if ($isTaskUpdate) {

                $updateText = trim(str_ireplace('#taskupdate:', '', $originalText));

                // get sheet name (same logic as task assign)
                $user = $this->getSlackUserInfo($userId);
                $userName = $user['name'] ?? 'Unknown';

                $this->updateTaskInSheet(
                    $userName,
                    $updateText,
                    $time
                );

                Log::info("Task Updated", [
                    'user' => $userName,
                    'update' => $updateText
                ]);

                return response()->json(['success' => true]);
            }

            $isTaskRemark = str_contains(strtolower($originalText), '#taskremark:');

            if ($isTaskRemark) {

                if (!$isManager) {

                    Log::warning("Task Remark Blocked (Unauthorized)", [
                        'user_email' => $email,
                        'user_name'  => $name,
                        'text'       => $originalText,
                        'time'       => $time,
                    ]);

                    return response()->json(['success' => true]);
                }

                preg_match('/<@([A-Z0-9]+)>/', $originalText, $match);
                $targetUserId = $match[1] ?? null;

                if ($targetUserId) {

                    $targetUser = $this->getSlackUserInfo($targetUserId);
                    $targetUserName = $targetUser['name'] ?? 'Unknown';

                    $remarkText = preg_replace('/#taskremark:\s*/i', '', $originalText);
                    $remarkText = preg_replace('/<@[A-Z0-9]+>/', '', $remarkText);
                    $remarkText = trim($remarkText);

                    $this->addRemarkToTask($targetUserName, $remarkText, $time);

                    Log::info("Task Remark Added Successfully", [
                        'added_by'   => $email,
                        'target_user'=> $targetUserName,
                        'remark'     => $remarkText,
                        'time'       => $time,
                    ]);

                    return response()->json(['success' => true]);
                }
            }

            /*
            -------------------------------------------------
            🔄 NORMAL FLOW STARTS HERE
            -------------------------------------------------
            */

            $textToSave = $this->convertMentionsToNames($originalText);

            $textLower = strtolower($textToSave);

            $isCheckin =
                str_contains($textLower, 'checkin') ||
                str_contains($textLower, 'check in') ||
                str_contains($textLower, 'check-in');

            $isCheckout =
                str_contains($textLower, 'checkout') ||
                str_contains($textLower, 'check out') ||
                str_contains($textLower, 'check-out');

            if ($isCheckin || $isCheckout) {

                Log::info("🔥 ATTENDANCE TRIGGERED", [
                    'text' => $textLower
                ]);

                $this->saveOrUpdateAttendance(
                    $name,
                    $email,
                    $textLower,
                    $time
                );

                return response()->json(['success' => true]);
            }

            $isBreakIn =
                str_contains($textLower, 'breakin') ||
                str_contains($textLower, 'break in') ||
                str_contains($textLower, 'break-in');

            $isBreakOut =
                str_contains($textLower, 'breakout') ||
                str_contains($textLower, 'break out') ||
                str_contains($textLower, 'break-out');

            if ($isBreakIn || $isBreakOut) {

                $this->saveOrUpdateBreak(
                    $name,
                    $email,
                    $textLower,
                    $time
                );

                return response()->json(['success' => true]);
            }

            // MESSAGE FLOW
            $type = 'MESSAGE';

            $check = $this->checkAbusive($textToSave);

            $textToSave = $check['message'];
            $abusiveText = $check['abusive'];

            $this->saveToSheet(
                $name,
                $email,
                $type,
                $textToSave,
                $abusiveText,
                $attachment,
                $time
            );

            Log::info("Message detected", [
                'name' => $name,
                'email' => $email,
                'message' => $textToSave,
                'time' => $time,
                'event_id' => $eventId
            ]);

        } catch (\Exception $e) {

            Log::error("Slack attendance error", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function saveToSheet($name, $email, $type, $message, $abusive, $attachment, $time)
    {
        try {
            Log::info("Connecting to Google Sheets");

            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(Sheets::SPREADSHEETS);

            $service = new Sheets($client);
            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";

            $values = [[$name, $email, $type, $message, $abusive, $attachment, $time]];
            $body = new \Google\Service\Sheets\ValueRange(['values'=>$values]);
            $params = ['valueInputOption'=>'RAW'];

            $service->spreadsheets_values->append(
                $spreadsheetId,
                'Sheet1!A:G',
                $body,
                $params
            );

            Log::info("Data successfully appended to Google Sheet");
        } catch (\Exception $e) {
            Log::error("Google Sheet write failed", ['error' => $e->getMessage()]);
        }
    }
    private function checkAbusive($message)
    {
        Log::info("Checking abusive for message", ['message' => $message]);

        try {
            $user = env('NEUTRINO_USER');
            $apiKey = env('NEUTRINO_PASS');

            $data = http_build_query([
                'user-id' => $user,
                'api-key' => $apiKey,
                'content' => $message
            ]);

            $options = [
                'http' => [
                    'header'  => "Content-type: application/x-www-form-urlencoded",
                    'method'  => 'POST',
                    'content' => $data,
                ]
            ];

            $context  = stream_context_create($options);
            $response = file_get_contents("https://neutrinoapi.net/bad-word-filter", false, $context);


            $result = json_decode($response, true);


            if (!empty($result['is-bad'])) {
                Log::info("Abusive detected", ['bad_words' => $result['bad-words-list'] ?? []]);
                return [
                    'is_abusive' => true,
                    'abusive' => $message,
                    'message' => ''
                ];
            }

            Log::info("Message is clean", ['message' => $message]);

        } catch (\Exception $e) {
            Log::error("Abusive API error", ['error' => $e->getMessage()]);
        }

        return [
            'is_abusive' => false,
            'abusive' => '',
            'message' => $message
        ];
    }
    private function convertMentionsToNames($text)
    {
        $workspaceUrl = env('SLACK_WORKSPACE_URL');

        preg_match_all('/<@([A-Z0-9]+)>/', $text, $matches);

        foreach ($matches[1] as $mentionedUserId) {

            $mentionedUser = $this->getSlackUserInfo($mentionedUserId);
            $mentionedName = $mentionedUser['name'] ?? 'Unknown';

            $profileUrl = $workspaceUrl . "/team/" . $mentionedUserId;

            $replacement = "@{$mentionedName} {$profileUrl} ";

            $text = str_replace("<@{$mentionedUserId}>", $replacement, $text);
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
        // 2️⃣ Check for abusive content using Neutrino API

        $type = 'MESSAGE';
        $check = $this->checkAbusive($message);
        $messageToSave = $check['message'];
        $abusiveText = $check['abusive'];

        $time = now()->timezone('Asia/Karachi')->format('Y-m-d H:i:s');
        $attachment = '';

        $this->saveToSheet($slackName, $projectUser->email, $type,$messageToSave, $abusiveText, $attachment,$time);

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
    }


    private function saveOrUpdateAttendance($name, $email, $text, $time)
    {
        try {
            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(\Google\Service\Sheets::SPREADSHEETS);

            $service = new \Google\Service\Sheets($client);
            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";

            $sheetName = "Attendance";

            $response = $service->spreadsheets_values->get($spreadsheetId, "$sheetName!A:J");
            $rows = $response->getValues() ?? [];

            $isCheckout = str_contains($text, 'out');

            foreach ($rows as $index => $row) {

                if ($index === 0) continue;

                $rowEmail = $row[1] ?? '';
                $checkInTime = $row[2] ?? '';
                $checkOutTime = $row[3] ?? '';

                if (!$rowEmail || !$checkInTime) continue;

                // 🔥 Only rows without checkout
                if ($rowEmail === $email && empty($checkOutTime)) {

                    $checkInTime = ltrim($checkInTime, " '");
                    $timeClean   = ltrim($time, " '");

                    $carbon = \Carbon\Carbon::parse($timeClean, 'Asia/Karachi');
                    $dayName = $carbon->format('l'); // Monday, Tuesday etc.

                    $checkIn = \Carbon\Carbon::parse($checkInTime, 'Asia/Karachi');
                    $current = \Carbon\Carbon::parse($timeClean, 'Asia/Karachi');

                    // 🔥 15 HOURS RULE
                    $diffHours = $checkIn->diffInHours($current);

                    if ($diffHours <= 15) {

                        if ($isCheckout) {

                            $totalMinutes = $checkIn->diffInMinutes($current);

                            $hours = floor($totalMinutes / 60);
                            $minutes = $totalMinutes % 60;

                            $totalHours = "{$hours}h {$minutes}m";

                            $rowIndex = $index + 1;

                            $values = [[
                                $row[0],
                                $row[1],
                                $row[2],
                                $timeClean,
                                $dayName,
                                $timeClean,
                                $totalHours,
                                $row[7] ?? '',
                                $row[8] ?? '',
                                $row[9] ?? ''
                            ]];

                            $body = new \Google\Service\Sheets\ValueRange([
                                'values' => $values
                            ]);

                            $service->spreadsheets_values->update(
                                $spreadsheetId,
                                "$sheetName!A{$rowIndex}:G{$rowIndex}",
                                $body,
                                ['valueInputOption' => 'RAW']
                            );

                            Log::info("✅ Checkout updated (within 15 hours)", [
                                'email' => $email,
                                'hours' => $totalHours
                            ]);

                            return;
                        }
                    }
                }
            }

            // 🔥 CHECK-IN (NEW SHIFT)
            if (str_contains($text, 'in')) {

                $values = [[
                    $name,
                    $email,
                    $time,
                    '',
                    $dayName,
                    $time,
                    '',
                    '',
                    '',
                    ''
                ]];

                $body = new \Google\Service\Sheets\ValueRange([
                    'values' => $values
                ]);

                $service->spreadsheets_values->append(
                    $spreadsheetId,
                    "$sheetName!A:G",
                    $body,
                    ['valueInputOption' => 'RAW']
                );

                Log::info("✅ New shift (check-in)", [
                    'email' => $email,
                    'time' => $time
                ]);
            }

        } catch (\Exception $e) {

            Log::error("❌ Attendance error", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function saveOrUpdateBreak($name, $email, $text, $time)
    {
        try {

            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(\Google\Service\Sheets::SPREADSHEETS);

            $service = new \Google\Service\Sheets($client);

            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";
            $sheetName = "Attendance";

            $response = $service->spreadsheets_values->get($spreadsheetId, "$sheetName!A:J");
            $rows = $response->getValues() ?? [];

            $isBreakOut = str_contains($text, 'out');

            foreach ($rows as $index => $row) {

                if ($index === 0) continue;

                $rowEmail = $row[1] ?? '';
                $checkOutTime = $row[3] ?? '';

                // 🔥 SAME LOGIC AS ATTENDANCE
                if ($rowEmail === $email && empty($checkOutTime)) {

                    $rowIndex = $index + 1;

                    $breakStart = $row[7] ?? '';
                    $breakEnd   = $row[8] ?? '';

                    // 🟢 BREAK IN (start break)
                    if (!$isBreakOut) {

                        $values = [[
                            $row[0],
                            $row[1],
                            $row[2],
                            $row[3],
                            $row[4] ?? '',
                            $row[5] ?? '',
                            $row[6] ?? '',
                            $time,
                            '',
                            ''
                        ]];

                        $service->spreadsheets_values->update(
                            $spreadsheetId,
                            "$sheetName!A{$rowIndex}:J{$rowIndex}",
                            new \Google\Service\Sheets\ValueRange(['values' => $values]),
                            ['valueInputOption' => 'RAW']
                        );

                        return;
                    }

                    // BREAK OUT (end break)
                    if ($isBreakOut && !empty($breakStart)) {

                        $start = \Carbon\Carbon::parse($breakStart, 'Asia/Karachi');
                        $end   = \Carbon\Carbon::parse($time, 'Asia/Karachi');

                        $minutes = $start->diffInMinutes($end);

                        $hours = floor($minutes / 60);
                        $mins  = $minutes % 60;

                        $totalBreak = "{$hours}h {$mins}m";

                        $values = [[
                            $row[0],
                            $row[1],
                            $row[2],
                            $row[3],
                            $row[4] ?? '',
                            $row[5] ?? '',
                            $row[6] ?? '',
                            $breakStart,
                            $time,
                            $totalBreak
                        ]];

                        $service->spreadsheets_values->update(
                            $spreadsheetId,
                            "$sheetName!A{$rowIndex}:J{$rowIndex}",
                            new \Google\Service\Sheets\ValueRange(['values' => $values]),
                            ['valueInputOption' => 'RAW']
                        );

                        return;
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error("Break error", ['error' => $e->getMessage()]);
        }
    }

    private function saveTaskToSheet($userName, $task, $time)
    {
        try {

            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(\Google\Service\Sheets::SPREADSHEETS);

            $service = new \Google\Service\Sheets($client);

            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";

            // STEP 1: check sheet exists
            $spreadsheet = $service->spreadsheets->get($spreadsheetId);

            $exists = false;

            foreach ($spreadsheet->getSheets() as $sheet) {
                if ($sheet->getProperties()->getTitle() === $userName) {
                    $exists = true;
                    break;
                }
            }

            // STEP 2: create sheet if not exists2
            if (!$exists) {

                $requests = [
                    new \Google_Service_Sheets_Request([
                        'addSheet' => [
                            'properties' => [
                                'title' => $userName
                            ]
                        ]
                    ])
                ];

                $batchUpdateRequest = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                    'requests' => $requests
                ]);

                $service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);

                // header row
                $header = new \Google_Service_Sheets_ValueRange([
                    'values' => [['Task', 'Description', 'Remarks', 'Created At']]
                ]);

                $service->spreadsheets_values->append(
                    $spreadsheetId,
                    $userName . "!A:D",
                    $header,
                    ['valueInputOption' => 'RAW']
                );
            }

            // STEP 3: insert task
            $values = [[
                $task,
                '',
                '',
                $time
            ]];

            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);

            $service->spreadsheets_values->append(
                $spreadsheetId,
                $userName . "!A:D",
                $body,
                ['valueInputOption' => 'RAW']
            );

        } catch (\Exception $e) {

            Log::error("Task Sheet Error", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function updateTaskInSheet($userName, $updateText, $time)
    {
        try {

            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(\Google\Service\Sheets::SPREADSHEETS);

            $service = new \Google\Service\Sheets($client);

            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";

            /*
            ------------------------------------------
            STEP 1: CHECK SHEET EXISTS
            ------------------------------------------
            */
            $spreadsheet = $service->spreadsheets->get($spreadsheetId);

            $exists = false;

            foreach ($spreadsheet->getSheets() as $sheet) {
                if ($sheet->getProperties()->getTitle() === $userName) {
                    $exists = true;
                    break;
                }
            }

            /*
            ------------------------------------------
            STEP 2: CREATE SHEET IF NOT EXISTS
            ------------------------------------------
            */
            if (!$exists) {

                $requests = [
                    new \Google_Service_Sheets_Request([
                        'addSheet' => [
                            'properties' => [
                                'title' => $userName
                            ]
                        ]
                    ])
                ];

                $batchUpdateRequest = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                    'requests' => $requests
                ]);

                $service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);

                // header row
                $header = new \Google_Service_Sheets_ValueRange([
                    'values' => [['Task', 'Description', 'Remarks', 'Created At']]
                ]);

                $service->spreadsheets_values->append(
                    $spreadsheetId,
                    $userName . "!A:D",
                    $header,
                    ['valueInputOption' => 'RAW']
                );
            }

            /*
            ------------------------------------------
            STEP 3: GET SHEET DATA
            ------------------------------------------
            */
            $response = $service->spreadsheets_values->get(
                $spreadsheetId,
                $userName . "!A:D"
            );

            $rows = $response->getValues() ?? [];

            /*
            ------------------------------------------
            STEP 4: GET LAST ROW
            ------------------------------------------
            */
            $lastIndex = count($rows) - 1;
            $lastRow = $rows[$lastIndex] ?? [];

            $lastDescription = $lastRow[1] ?? '';

            /*
            ------------------------------------------
            CASE A: LAST ROW HAS DESCRIPTION → NEW ROW
            ------------------------------------------
            */
            if (!empty($lastDescription)) {

                $values = [[
                    'No Task Assigned',
                    $updateText,
                    '',
                    $time
                ]];

                $service->spreadsheets_values->append(
                    $spreadsheetId,
                    $userName . "!A:D",
                    new \Google\Service\Sheets\ValueRange([
                        'values' => $values
                    ]),
                    ['valueInputOption' => 'RAW']
                );

                Log::info("New task row created (previous already updated)", [
                    'user' => $userName,
                    'update' => $updateText
                ]);

                return;
            }

            /*
            ------------------------------------------
            CASE B: UPDATE EXISTING ROW
            ------------------------------------------
            */
            $rowIndex = $lastIndex + 1;

            $values = [[
                $lastRow[0] ?? 'No Task',
                $updateText,
                $lastRow[2] ?? '',
                $lastRow[3] ?? ''
            ]];

            $service->spreadsheets_values->update(
                $spreadsheetId,
                $userName . "!A{$rowIndex}:D{$rowIndex}",
                new \Google\Service\Sheets\ValueRange([
                    'values' => $values
                ]),
                ['valueInputOption' => 'RAW']
            );

            Log::info("Task updated successfully", [
                'user' => $userName,
                'row' => $rowIndex
            ]);

        } catch (\Exception $e) {

            Log::error("Task update error", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function addRemarkToTask($userName, $remarkText, $time)
    {
        try {

            $client = new Client();
            $client->setAuthConfig(storage_path('app/google.json'));
            $client->addScope(\Google\Service\Sheets::SPREADSHEETS);

            $service = new \Google\Service\Sheets($client);

            $spreadsheetId = "1GRhsV3ypwhtg08_-gsVkXWYee13Gc2PnckRWfTIHDHA";

            $sheetName = $userName;

            $response = $service->spreadsheets_values->get(
                $spreadsheetId,
                $sheetName . "!A:D"
            );

            $rows = $response->getValues() ?? [];

            if (count($rows) <= 1) return;

            for ($i = count($rows) - 1; $i > 0; $i--) {

                if (!empty($rows[$i][0])) {

                    $rowIndex = $i + 1;

                    $values = [[
                        $rows[$i][0] ?? '',
                        $rows[$i][1] ?? '',
                        $remarkText,
                        $rows[$i][3] ?? ''
                    ]];

                    $body = new \Google\Service\Sheets\ValueRange([
                        'values' => $values
                    ]);

                    $service->spreadsheets_values->update(
                        $spreadsheetId,
                        $sheetName . "!A{$rowIndex}:D{$rowIndex}",
                        $body,
                        ['valueInputOption' => 'RAW']
                    );

                    Log::info("Remark added to task", [
                        'user' => $userName,
                        'row' => $rowIndex
                    ]);

                    return;
                }
            }

        } catch (\Exception $e) {

            Log::error("Task remark error", [
                'error' => $e->getMessage()
            ]);
        }
    }

}
