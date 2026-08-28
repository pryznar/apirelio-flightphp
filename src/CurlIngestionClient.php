<?php

declare(strict_types=1);

namespace Apirelio\FlightPHP;

use Apirelio\Core\Contracts\IngestionClient;
use CurlHandle;
use JsonException;
use RuntimeException;

final readonly class CurlIngestionClient implements IngestionClient
{
    /** @throws JsonException */
    public function postBatch(
        string $endpoint,
        string $apiKey,
        array $events,
        float $timeoutSeconds,
        float $connectTimeoutSeconds,
    ): void {
        $curl = curl_init($endpoint);
        if (! $curl instanceof CurlHandle) {
            throw new RuntimeException('Unable to initialize the Apirelio HTTP client.');
        }

        $payload = json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeoutSeconds * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeoutSeconds * 1000)),
        ]);

        try {
            $response = curl_exec($curl);
            if ($response === false) {
                throw new RuntimeException('Apirelio request failed: '.curl_error($curl));
            }

            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf('Apirelio ingestion returned HTTP %d.', $status));
            }
        } finally {
            curl_close($curl);
        }
    }
}
