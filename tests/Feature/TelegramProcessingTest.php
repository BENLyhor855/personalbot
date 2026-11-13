<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Services\AssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_retry_does_not_duplicate_history_or_call_ai_again(): void
    {
        config(['personal_assistant.token' => 'test']);
        DB::table('telegram_updates')->insert([
            'update_id' => 42,
            'payload' => json_encode(['message' => ['from' => ['id' => 123], 'chat' => ['id' => 123], 'text' => 'Hello']]),
            'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assistant = $this->mock(AssistantService::class);
        $assistant->shouldReceive('reply')->once()->andReturn(['content' => 'Hi', 'provider' => 'local', 'model' => 'test']);
        Http::fakeSequence()->push([], 500)->push(['ok' => true], 200);
        $job = new ProcessTelegramUpdate(42);
        try {
            $job->handle($assistant);
            $this->fail('The first delivery should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Telegram delivery failed', $e->getMessage());
        }
        $job->handle($assistant);
        $this->assertDatabaseCount('telegram_messages', 2);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 42, 'state' => 'processed', 'reply' => 'Hi']);
    }

    public function test_recovery_requeues_only_stale_pending_updates(): void
    {
        Queue::fake();
        DB::table('telegram_updates')->insert([
            ['update_id' => 1, 'payload' => '{}', 'state' => 'pending', 'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
            ['update_id' => 2, 'payload' => '{}', 'state' => 'processed', 'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
            ['update_id' => 3, 'payload' => '{}', 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->artisan('assistant:telegram-updates:recover')->assertSuccessful();
        Queue::assertPushed(ProcessTelegramUpdate::class, fn ($job) => $job->updateId === 1);
        Queue::assertPushed(ProcessTelegramUpdate::class, 1);
    }
}
