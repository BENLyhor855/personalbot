<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $secret = config('personal_assistant.secret');
        if (! is_string($secret) || $secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }
        $data = $request->all();
        $id = $data['update_id'] ?? null;
        $message = $data['message'] ?? [];
        if (! is_int($id)) {
            return response()->json(['ok' => false], 400);
        }
        if (($message['chat']['type'] ?? '') !== 'private' || ! in_array((string) ($message['from']['id'] ?? ''), config('personal_assistant.allowed_ids'), true)) {
            return response()->json(['ok' => true]);
        }
        $inserted = DB::table('telegram_updates')->insertOrIgnore(['update_id' => $id, 'payload' => json_encode($data), 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if ($inserted) {
            try {
                ProcessTelegramUpdate::dispatch($id)->onQueue('telegram');
            } catch (\Throwable $e) {
                DB::table('telegram_updates')->where('update_id', $id)->delete();
                throw $e;
            }
        }

        return response()->json(['ok' => true]);
    }
}
