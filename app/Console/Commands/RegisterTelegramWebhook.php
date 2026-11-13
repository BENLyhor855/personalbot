<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterTelegramWebhook extends Command
{
    protected $signature = 'assistant:telegram-webhook:register {--drop-pending}';

    protected $description = 'Register the Telegram webhook';

    public function handle(): int
    {
        $c = config('personal_assistant');
        if (empty($c['token']) || empty($c['url']) || empty($c['allowed_ids']) || empty($c['secret']) || ! preg_match('/^[A-Za-z0-9_-]{1,256}$/', $c['secret']) || ! str_starts_with($c['url'], 'https://')) {
            $this->error('Configure the token, HTTPS URL, allowed user IDs and a valid webhook secret first.');

            return self::FAILURE;
        }
        try {
            $result = Http::timeout(20)->post('https://api.telegram.org/bot'.$c['token'].'/setWebhook', ['url' => $c['url'], 'secret_token' => $c['secret'], 'allowed_updates' => ['message'], 'drop_pending_updates' => (bool) $this->option('drop-pending')])->throw()->json();
            if (empty($result['ok'])) {
                throw new \RuntimeException('Telegram rejected webhook registration.');
            }
            $this->info('Webhook registered.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Webhook registration failed. Check configuration and connectivity.');

            return self::FAILURE;
        }
    }
}
