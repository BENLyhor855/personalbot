<?php

namespace App\Jobs;

use App\Services\AssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $updateId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('telegram-update-'.$this->updateId))->expireAfter(150)];
    }

    public function handle(AssistantService $assistant): void
    {
        $update = DB::table('telegram_updates')->where('update_id', $this->updateId)->first();
        if (! $update || $update->state === 'processed') {
            return;
        }

        $message = json_decode($update->payload, true)['message'];
        $owner = $message['from']['id'];
        $chat = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        DB::table('telegram_conversations')->insertOrIgnore([
            'owner_id' => $owner, 'chat_id' => $chat, 'mode' => 'auto',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conversation = DB::table('telegram_conversations')->where('owner_id', $owner)->first();
        DB::table('telegram_conversations')->where('id', $conversation->id)->update([
            'chat_id' => $chat, 'last_activity' => now(), 'updated_at' => now(),
        ]);

        $reply = $update->reply ?? $this->createReply($text, $owner, $conversation, $assistant);
        if ($update->reply === null) {
            DB::table('telegram_updates')->where('update_id', $this->updateId)->update([
                'reply' => $reply, 'updated_at' => now(),
            ]);
        }
        $this->sendReply($chat, $reply);
        DB::table('telegram_updates')->where('update_id', $this->updateId)->update([
            'state' => 'processed', 'processed_at' => now(), 'last_error' => null, 'updated_at' => now(),
        ]);
    }

    private function createReply(string $text, int $owner, object $conversation, AssistantService $assistant): string
    {
        if ($text === '') {
            return 'Please send a text message.';
        }
        $parts = preg_split('/\s+/', $text, 2);
        $command = strtolower(explode('@', $parts[0])[0]);
        if (in_array($command, ['/start', '/help'], true)) {
            return 'Commands: /help, /whoami, /status, /model auto|cloud|local, /clear';
        }
        if ($command === '/whoami') {
            return 'Your Telegram user ID: '.$owner;
        }
        if ($command === '/status') {
            return 'Assistant is running. Model mode: '.$conversation->mode;
        }
        if ($command === '/clear') {
            DB::table('telegram_messages')->where('conversation_id', $conversation->id)->delete();

            return 'Conversation history cleared.';
        }
        if ($command === '/model') {
            $mode = strtolower(trim($parts[1] ?? ''));
            if (! in_array($mode, ['auto', 'cloud', 'local'], true)) {
                return 'Usage: /model auto|cloud|local';
            }
            DB::table('telegram_conversations')->where('id', $conversation->id)->update(['mode' => $mode, 'updated_at' => now()]);

            return 'Model mode set to '.$mode.'.';
        }
        if (str_starts_with($text, '/')) {
            return 'Unknown command. Send /help for commands.';
        }

        $history = DB::table('telegram_messages')->where('conversation_id', $conversation->id)
            ->orderByDesc('id')->limit(30)->get()->reverse()
            ->map(fn ($message) => ['role' => $message->role, 'content' => $message->content])->values()->all();
        $history[] = ['role' => 'user', 'content' => $text];
        $result = $assistant->reply($history, $conversation->mode);

        DB::transaction(function () use ($conversation, $text, $result) {
            DB::table('telegram_messages')->insert([
                ['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $text, 'provider' => null, 'model' => null, 'created_at' => now(), 'updated_at' => now()],
                ['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $result['content'], 'provider' => $result['provider'], 'model' => $result['model'], 'created_at' => now(), 'updated_at' => now()],
            ]);
            DB::table('telegram_updates')->where('update_id', $this->updateId)->update([
                'reply' => $result['content'], 'updated_at' => now(),
            ]);
        });

        return $result['content'];
    }

    private function sendReply(int $chat, string $reply): void
    {
        try {
            foreach (mb_str_split($reply, 4000) as $chunk) {
                Http::timeout(20)->post('https://api.telegram.org/bot'.config('personal_assistant.token').'/sendMessage', [
                    'chat_id' => $chat, 'text' => $chunk,
                ])->throw();
            }
        } catch (Throwable $e) {
            // Avoid exposing the token-bearing URL in logs or failed jobs.
            throw new RuntimeException('Telegram delivery failed');
        }
    }

    public function failed(Throwable $e): void
    {
        DB::table('telegram_updates')->where('update_id', $this->updateId)->update([
            'state' => 'failed', 'last_error' => substr($e->getMessage(), 0, 1000), 'updated_at' => now(),
        ]);
    }
}
