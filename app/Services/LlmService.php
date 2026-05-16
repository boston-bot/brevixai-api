<?php

namespace App\Services;

class LlmService
{
    /**
     * Streams the LLM chat completion using the configured provider.
     *
     * @param array $messages Array of messages with 'role' and 'content'
     * @param string|null $systemPrompt Optional system instructions
     * @param callable $onChunk Callback invoked for each text chunk received
     * @throws \Exception
     */
    public function streamChat(array $messages, ?string $systemPrompt, callable $onChunk): void
    {
        $provider = env('LLM_PROVIDER', 'openai-compatible');
        $model = env('LLM_MODEL', 'gemma-4-e4b-it');
        $apiKey = env('LLM_API_KEY', 'local-not-used');
        $baseUrl = env('LLM_BASE_URL', 'http://127.0.0.1:1234/v1');

        if ($provider === 'anthropic') {
            $url = 'https://api.anthropic.com/v1/messages';
            $headers = [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
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
            $url = rtrim($baseUrl, '/') . '/chat/completions';
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];

            $formattedMessages = $messages;
            if ($systemPrompt) {
                array_unshift($formattedMessages, [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ]);
            }

            $postData = [
                'model' => $model,
                'messages' => $formattedMessages,
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
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, $provider, $onChunk) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (empty($line)) continue;

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
        if (!$success) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("LLM request failed: " . $error);
        }
        curl_close($ch);
    }
}
