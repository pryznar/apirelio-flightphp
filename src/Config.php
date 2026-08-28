<?php

declare(strict_types=1);

namespace Apirelio\FlightPHP;

final readonly class Config
{
    /**
     * @param list<string> $paths
     * @param list<string> $captureHeaders
     * @param list<string> $metadataKeys
     */
    public function __construct(
        public string $apiKey,
        public string $endpoint = 'https://apirelio.com',
        public string $service = 'flightphp',
        public string $environment = 'production',
        public ?string $release = null,
        public bool $enabled = true,
        public array $paths = ['/api/*'],
        public array $captureHeaders = [],
        public array $metadataKeys = [],
        public float $timeoutSeconds = 2.0,
        public float $connectTimeoutSeconds = 0.5,
        public ?string $bufferPath = null,
        public int $batchSize = 500,
        public int $flushIntervalSeconds = 10,
    ) {}
}
