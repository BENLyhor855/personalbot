<?php
namespace Tests\Feature;
use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
class TelegramWebhookTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp(): void { parent::setUp(); config(['personal_assistant.secret'=>'secret', 'personal_assistant.allowed_ids'=>['123']]); Queue::fake(); }
 public function test_secret_is_required(): void { $this->postJson('/api/telegram/webhook', [])->assertForbidden(); }
 public function test_unauthorized_user_is_ignored(): void {
  $this->withHeader('X-Telegram-Bot-Api-Secret-Token','secret')->postJson('/api/telegram/webhook', ['update_id'=>1,'message'=>['from'=>['id'=>999], 'chat'=>['id'=>999,'type'=>'private']]])->assertOk();
  $this->assertDatabaseCount('telegram_updates',0); Queue::assertNothingPushed();
 }
 public function test_authorized_update_is_queued_once(): void {
  $data=['update_id'=>2,'message'=>['from'=>['id'=>123], 'chat'=>['id'=>123,'type'=>'private'],'text'=>'hello']];
  $this->withHeader('X-Telegram-Bot-Api-Secret-Token','secret')->postJson('/api/telegram/webhook',$data)->assertOk();
  $this->withHeader('X-Telegram-Bot-Api-Secret-Token','secret')->postJson('/api/telegram/webhook',$data)->assertOk();
  $this->assertDatabaseCount('telegram_updates',1); Queue::assertPushed(ProcessTelegramUpdate::class,1);
 }
}
