<?php

namespace App\Services\Agents;

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
            throw new RuntimeException('Brevix agent service request failed.', $response->status());
        }

        $body = $response->json();
        if (!is_array($body)) {
            throw new RuntimeException('Brevix agent service returned an invalid response.');
        }

        return $body;
    }
}
