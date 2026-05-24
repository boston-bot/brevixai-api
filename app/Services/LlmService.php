<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LlmService
{
    /**
     * Returns one non-streamed LLM chat response.
     *
     * @param  array  $messages  Array of messages with 'role' and 'content'
     * @param  string|null  $systemPrompt  Optional system instructions
     * @param  array  $options  Optional model/provider request overrides
     */
    public function completeChat(array $messages, ?string $systemPrompt, array $options = []): string
    {
        $provider = $this->provider();
        $model = (string) ($options['model'] ?? $this->model());
        $apiKey = $this->apiKey();
        $timeout = $this->timeout();

        if ($provider === 'anthropic') {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => (int) ($options['max_tokens'] ?? 1000),
            ];
            if ($systemPrompt) {
                $payload['system'] = $systemPrompt;
            }

            $response = Http::timeout($timeout)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->acceptJson()
                ->post('https://api.anthropic.com/v1/messages', $payload);

            if ($response->failed()) {
                throw new RuntimeException(sprintf('LLM request failed with status %d.', $response->status()), $response->status());
            }

            $body = $response->json();
            if (! is_array($body)) {
                throw new RuntimeException('LLM returned an invalid response.');
            }

            return collect($body['content'] ?? [])
                ->filter(fn ($part): bool => is_array($part) && ($part['type'] ?? null) === 'text')
                ->map(fn (array $part): string => (string) ($part['text'] ?? ''))
                ->implode('');
        }

        $payload = [
            'model' => $model,
            'messages' => $this->withSystemPrompt($messages, $systemPrompt),
        ];

        if (($options['json'] ?? false) === true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withToken($apiKey)
            ->post(rtrim($this->baseUrl(), '/').'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('LLM request failed with status %d.', $response->status()), $response->status());
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('LLM returned an invalid response.');
        }

        return (string) ($body['choices'][0]['message']['content'] ?? '');
    }

    /**
     * Returns a decoded JSON object from a non-streamed LLM chat response.
     *
     * @param  array  $messages  Array of messages with 'role' and 'content'
     */
    public function completeJson(array $messages, ?string $systemPrompt, array $options = []): array
    {
        $content = $this->completeChat($messages, $systemPrompt, array_merge($options, ['json' => true]));
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            $jsonStart = strpos($content, '{');
            $jsonEnd = strrpos($content, '}');
            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $decoded = json_decode(substr($content, $jsonStart, $jsonEnd - $jsonStart + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('LLM did not return valid JSON.');
        }

        return $decoded;
    }

    /**
     * Streams the LLM chat completion using the configured provider.
     *
     * @param  array  $messages  Array of messages with 'role' and 'content'
     * @param  string|null  $systemPrompt  Optional system instructions
     * @param  callable  $onChunk  Callback invoked for each text chunk received
     *
     * @throws \Exception
     */
    public function streamChat(array $messages, ?string $systemPrompt, callable $onChunk): void
    {
        $provider = $this->provider();
        $model = $this->model();
        $apiKey = $this->apiKey();
        $baseUrl = $this->baseUrl();

        if ($provider === 'anthropic') {
            $url = 'https://api.anthropic.com/v1/messages';
            $headers = [
                'Content-Type: application/json',
                'x-api-key: '.$apiKey,
                'anthropic-version: 2023-06-01',
            ];

            $postData = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 4000,
                'stream' => true,
            ];
            if ($systemPrompt) {
                $postData['system'] = $systemPrompt;
            }
        } else {
            // OpenAI or OpenAI-compatible (like LM Studio, Ollama)
            $url = rtrim($baseUrl, '/').'/chat/completions';
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer '.$apiKey,
            ];

            $postData = [
                'model' => $model,
                'messages' => $this->withSystemPrompt($messages, $systemPrompt),
                'stream' => true,
            ];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // Turn off cURL's internal buffering
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 512);

        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer, $provider, $onChunk) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (empty($line)) {
                    continue;
                }

                if ($provider === 'anthropic') {
                    if (str_starts_with($line, 'data:')) {
                        $jsonStr = trim(substr($line, 5));
                        $decoded = json_decode($jsonStr, true);
                        if ($decoded && isset($decoded['type'])) {
                            if ($decoded['type'] === 'content_block_delta' && isset($decoded['delta']['text'])) {
                                $onChunk($decoded['delta']['text']);
                            }
                        }
                    }
                } else {
                    // OpenAI-compatible / LM Studio
                    if (str_starts_with($line, 'data:')) {
                        $jsonStr = trim(substr($line, 5));
                        if ($jsonStr === '[DONE]') {
                            break;
                        }
                        $decoded = json_decode($jsonStr, true);
                        if ($decoded && isset($decoded['choices'][0]['delta']['content'])) {
                            $onChunk($decoded['choices'][0]['delta']['content']);
                        }
                    }
                }
            }

            return strlen($data);
        });

        // Execute request
        $success = curl_exec($ch);
        if (! $success) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('LLM request failed: '.$error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new \Exception("LLM request failed with status {$status}.");
        }
    }

    public function routerModel(): string
    {
        return (string) config('services.llm.router_model', $this->model());
    }

    private function provider(): string
    {
        return strtolower((string) config('services.llm.provider', 'openai'));
    }

    private function model(): string
    {
        return (string) config('services.llm.model', 'chat-latest');
    }

    private function apiKey(): string
    {
        $apiKey = (string) config('services.llm.api_key', '');
        if ($apiKey === '') {
            if (! in_array($this->provider(), ['openai', 'anthropic'], true)) {
                return 'local-not-used';
            }

            throw new RuntimeException('LLM API key is not configured.');
        }

        return $apiKey;
    }

    private function baseUrl(): string
    {
        return (string) config('services.llm.base_url', 'https://api.openai.com/v1');
    }

    private function timeout(): int
    {
        return (int) config('services.llm.timeout', 60);
    }

    private function withSystemPrompt(array $messages, ?string $systemPrompt): array
    {
        if (! $systemPrompt) {
            return $messages;
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => $systemPrompt,
        ]);

        return $messages;
    }
}
