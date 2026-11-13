<?php
namespace App\Jobs;
use App\Services\AssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\Middleware\WithoutOverlapping;
class ProcessTelegramUpdate implements ShouldQueue
{
 use Queueable;
 public int $tries = 3;
 public int $timeout = 120;
 public function __construct(public int $updateId) {}
 public function middleware(): array { return [(new WithoutOverlapping('telegram-update-'.$this->updateId))->expireAfter(150)]; }
 public function handle(AssistantService $assistant): void
 {
  $update = DB::table('telegram_updates')->where('update_id', $this->updateId)->first();
  if (!$update || $update->state === 'processed') return;
  $message = json_decode($update->payload, true)['message'] ?? [];
  $owner = $message['from']['id']; $chat = $message['chat']['id']; $text = trim($message['text'] ?? '');
  DB::table('telegram_conversations')->insertOrIgnore(['owner_id'=>$owner, 'chat_id'=>$chat, 'mode'=>'auto', 'created_at'=>now(), 'updated_at'=>now()]);
  $conversation = DB::table('telegram_conversations')->where('owner_id',$owner)->first();
  DB::table('telegram_conversations')->where('id',$conversation->id)->update(['chat_id'=>$chat, 'last_activity'=>now(), 'updated_at'=>now()]);
  $parts = preg_split('/\s+/', $text, 2); $command = strtolower(explode('@', $parts[0])[0]);
  $reply = null;
  if ($text === '') $reply = 'Please send a text message.';
  elseif (in_array($command, ['/start','/help'])) $reply = "Commands: /help, /whoami, /status, /model auto|cloud|local, /clear";
  elseif ($command === '/whoami') $reply = 'Your Telegram user ID: '.$owner;
  elseif ($command === '/status') $reply = 'Assistant is running. Model mode: '.$conversation->mode;
  elseif ($command === '/clear') { DB::table('telegram_messages')->where('conversation_id',$conversation->id)->delete(); $reply = 'Conversation history cleared.'; }
  elseif ($command === '/model') {
   $mode = strtolower(trim($parts[1] ?? ''));
   if (!in_array($mode, ['auto','cloud','local'], true)) $reply = 'Usage: /model auto|cloud|local';
   else { DB::table('telegram_conversations')->where('id',$conversation->id)->update(['mode'=>$mode,'updated_at'=>now()]); $reply = 'Model mode set to '.$mode.'.'; }
  } elseif (str_starts_with($text, '/')) $reply = 'Unknown command. Send /help for commands.';
  else {
   $history = DB::table('telegram_messages')->where('conversation_id',$conversation->id)->orderByDesc('id')->limit(30)->get()->reverse()->map(fn ($m) => ['role'=>$m->role,'content'=>$m->content])->values()->all();
   $history[] = ['role'=>'user','content'=>$text];
   $result = $assistant->reply($history, $conversation->mode); $reply = $result['content'];
   DB::transaction(function () use ($conversation, $text, $result) {
    DB::table('telegram_messages')->insert([['conversation_id'=>$conversation->id,'role'=>'user','content'=>$text,'created_at'=>now(),'updated_at'=>now()], ['conversation_id'=>$conversation->id,'role'=>'assistant','content'=>$result['content'],'provider'=>$result['provider'],'model'=>$result['model'],'created_at'=>now(),'updated_at'=>now()]]);
   });
  }
  try { foreach (mb_str_split($reply, 4000) as $chunk) Http::timeout(20)->post('https://api.telegram.org/bot'.config('personal_assistant.token').'/sendMessage', ['chat_id'=>$chat,'text'=>$chunk])->throw(); } catch (\Throwable $e) { throw new \RuntimeException('Telegram delivery failed'); }
  DB::table('telegram_updates')->where('update_id',$this->updateId)->update(['state'=>'processed','processed_at'=>now(),'last_error'=>null,'updated_at'=>now()]);
 }
 public function failed(\Throwable $e): void { DB::table('telegram_updates')->where('update_id',$this->updateId)->update(['state'=>'failed','last_error'=>substr($e->getMessage(),0,1000),'updated_at'=>now()]); }
}
