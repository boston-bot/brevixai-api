<?php

namespace App\Services\Agents;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrevixAgentClient
{
    /**
     * @throws ConnectionException
     * @throws RuntimeException
     */
    public function run(array $payload): array
    {
        $baseUrl = rtrim((string) config('services.brevix_agent.base_url'), '/');
        $apiKey = (string) config('services.brevix_agent.api_key');
        $timeout = (int) config('services.brevix_agent.timeout', 60);

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Brevix agent service is not configured.');
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withToken($apiKey)
            ->post("{$baseUrl}/agent/run", $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                sprintf('Brevix agent service request failed with status %d.', $response->status()),
                $response->status()
            );
        }

        $body = $response->json();
        if (!is_array($body)) {
            throw new RuntimeException('Brevix agent service returned an invalid response.');
        }

        return $body;
    }

    /**
     * Stream an agent run, calling $onEvent for each SSE event received.
     *
     * Returns the assembled final state from the message.completed event.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(string $type, array $payload): void  $onEvent
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function runStream(array $payload, callable $onEvent): array
    {
        $baseUrl = rtrim((string) config('services.brevix_agent.base_url'), '/');
        $apiKey = (string) config('services.brevix_agent.api_key');
        $timeout = (int) config('services.brevix_agent.timeout', 120);

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Brevix agent service is not configured.');
        }

        $client = new GuzzleClient();
        $response = $client->post("{$baseUrl}/agent/run/stream", [
            RequestOptions::JSON => $payload,
            RequestOptions::HEADERS => [
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'text/event-stream',
            ],
            RequestOptions::STREAM => true,
            RequestOptions::TIMEOUT => $timeout,
            RequestOptions::READ_TIMEOUT => $timeout,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(
                sprintf('Brevix agent streaming request failed with status %d.', $response->getStatusCode()),
                $response->getStatusCode()
            );
        }

        $assembled = [];
        $body = $response->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(4096);
            $lines = explode("\n", $buffer);
            // Keep last (potentially incomplete) line in the buffer
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = rtrim($line);
                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $eventData = json_decode(substr($line, 6), true);
                if (! is_array($eventData)) {
                    continue;
                }

                $type = (string) ($eventData['type'] ?? '');
                $eventPayload = is_array($eventData['payload'] ?? null) ? $eventData['payload'] : [];

                if ($type === 'message.completed') {
                    $assembled = $eventPayload;
                }

                $onEvent($type, $eventPayload);
            }
        }

        return $assembled;
    }
}
