<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverTelegramUpdates extends Command
{
    protected $signature = 'assistant:telegram-updates:recover {--minutes=10 : Minimum age in minutes}';

    protected $description = 'Requeue stale pending Telegram updates';

    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 3) {
            $this->error('Minimum age must be at least 3 minutes, longer than the worker timeout.');

            return self::FAILURE;
        }
        $count = 0;
        DB::table('telegram_updates')->where('state', 'pending')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->orderBy('id')->chunkById(100, function ($updates) use (&$count) {
                foreach ($updates as $update) {
                    ProcessTelegramUpdate::dispatch($update->update_id)->onQueue('telegram');
                    DB::table('telegram_updates')->where('id', $update->id)->update(['updated_at' => now()]);
                    $count++;
                }
            });
        $this->info("Requeued {$count} pending update(s).");

        return self::SUCCESS;
    }
}
