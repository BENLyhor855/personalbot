<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AssistantService
{
    public function reply(array $messages, string $mode): array
    {
        $primary = config('personal_assistant.primary');
        if (! in_array($primary, ['cloud', 'local'], true)) {
            throw new RuntimeException('Invalid AI primary driver');
        }
        $providers = $mode === 'auto' ? [$primary, $primary === 'cloud' ? 'local' : 'cloud'] : [$mode];
        foreach ($providers as $provider) {
            try {
                $config = config('personal_assistant.'.$provider);
                if (empty($config['url']) || empty($config['model']) || empty($config['key'])) {
                    throw new RuntimeException('Provider is not configured');
                }
                $result = Http::timeout(40)->withToken($config['key'])->post(rtrim($config['url'], '/').'/chat/completions', ['model' => $config['model'], 'messages' => $messages, 'stream' => false])->throw()->json();
                $content = $result['choices'][0]['message']['content'] ?? null;
                if (! is_string($content) || trim($content) === '') {
                    throw new RuntimeException('Provider returned an empty response');
                }

                return ['content' => $content, 'provider' => $provider, 'model' => $config['model']];
            } catch (\Throwable $e) {
                $last = $e;
            }
        }
        throw new RuntimeException('AI provider unavailable', 0, $last ?? null);
    }
}
