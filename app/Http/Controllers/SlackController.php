<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Google\Client;
use Google\Service\Sheets;

class SlackController extends Controller
{

    public function attendance(Request $request)
    {

        Log::info("Slack request received", $request->all());

        $payload = $request->all();

        try {

            if(isset($payload['event']))
            {

                $text = strtolower($payload['event']['text'] ?? '');

                Log::info("Slack message text", ['text' => $text]);

                if(str_contains($text,'check in') || str_contains($text,'check out'))
                {

                    $type = str_contains($text,'check in') ? 'CHECK IN' : 'CHECK OUT';

                    $name = $payload['event']['username'] ?? 'Slack User';

                    $email = $payload['event']['user'] ?? 'unknown';

                    $time = now()->toDateTimeString();

                    Log::info("Attendance detected", [
                        'name' => $name,
                        'email' => $email,
                        'type' => $type,
                        'time' => $time
                    ]);

                    $this->saveToSheet($name,$email,$type,$time);

                }
                else
                {
                    Log::info("Message ignored - not attendance command");
                }

            }
            else
            {
                Log::info("No event key found in payload");
            }

        } catch (\Exception $e) {

            Log::error("Slack attendance error", [
                'message' => $e->getMessage()
            ]);

        }

        return response()->json(['success'=>true]);

    }


    private function saveToSheet($name,$email,$type,$time)
    {

        try {

            Log::info("Connecting to Google Sheets");

            $client = new Client();

            $client->setAuthConfig(storage_path('app/google.json'));

            $client->addScope(Sheets::SPREADSHEETS);

            $service = new Sheets($client);

            $spreadsheetId = "1r8spn7LWA5247CPJpN5Ke9keJDwhvu44MS9SbitelvM";

            $values = [
                [$name,$email,$type,$time]
            ];

            Log::info("Prepared data for sheet", $values);

            $body = new \Google\Service\Sheets\ValueRange([
                'values'=>$values
            ]);

            $params = [
                'valueInputOption'=>'RAW'
            ];

            $service->spreadsheets_values->append(
                $spreadsheetId,
                'Sheet1!A:D',
                $body,
                $params
            );

            Log::info("Data successfully appended to Google Sheet");

        } catch (\Exception $e) {

            Log::error("Google Sheet write failed", [
                'error' => $e->getMessage()
            ]);

        }

    }

}
