<?php
namespace Tests\Feature;
use App\Services\AssistantService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class AssistantServiceTest extends TestCase
{
 public function test_auto_falls_back_to_local(): void {
  config(['personal_assistant.primary'=>'cloud','personal_assistant.cloud'=>['url'=>'https://cloud.test/v1','key'=>'x','model'=>'cloud-model'],'personal_assistant.local'=>['url'=>'https://local.test/v1','key'=>'x','model'=>'local-model']]);
  Http::fake(['cloud.test/*'=>Http::response([],500),'local.test/*'=>Http::response(['choices'=>[['message'=>['content'=>'Hello']]]],200)]);
  $answer = app(AssistantService::class)->reply([['role'=>'user','content'=>'Hi']], 'auto');
  $this->assertSame('local', $answer['provider']); $this->assertSame('Hello', $answer['content']);
 }
}
