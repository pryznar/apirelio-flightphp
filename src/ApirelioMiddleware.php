<?php

declare(strict_types=1);

namespace Apirelio\FlightPHP;

use Apirelio\Core\Config\BufferConfig;
use Apirelio\Core\Config\TransportConfig;
use Apirelio\Core\Contracts\EventTransport;
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;
use Apirelio\Core\Data\EventContext;
use Apirelio\Core\EventFactory;
use Apirelio\Core\MetadataSanitizer;
use Apirelio\Core\Transport\FileBufferTransport;
use Apirelio\Core\Transport\HttpBatchTransport;
use Closure;
use flight\Engine;
use flight\net\Request;
use Throwable;

final class ApirelioMiddleware
{
    public const VERSION = '1.0.0';

    /** @var null|Closure(Engine<object>, array<string, mixed>): ?ApirelioCustomer */
    private ?Closure $customerResolver;

    /** @var null|Closure(Engine<object>, array<string, mixed>): (ApirelioApplication|string|null) */
    private ?Closure $applicationResolver;

    /** @var null|Closure(Throwable): void */
    private ?Closure $failureHandler;

    private readonly EventTransport $transport;

    /** @var Engine<object> */
    private readonly Engine $app;

    private ?RequestContext $requestContext = null;

    private int $startedAt = 0;

    private bool $active = false;

    /** @var array<string, mixed> */
    private array $routeParams = [];

    /**
     * @param Engine<object> $app
     * @param null|callable(Engine<object>, array<string, mixed>): ?ApirelioCustomer $customerResolver
     * @param null|callable(Engine<object>, array<string, mixed>): (ApirelioApplication|string|null) $applicationResolver
     * @param null|callable(Throwable): void $failureHandler
     */
    public function __construct(
        Engine $app,
        private readonly Config $config,
        ?callable $customerResolver = null,
        ?callable $applicationResolver = null,
        ?EventTransport $transport = null,
        ?callable $failureHandler = null,
        private readonly EventFactory $events = new EventFactory,
        private readonly MetadataSanitizer $metadata = new MetadataSanitizer,
    ) {
        $this->app = $app;
        $this->customerResolver = $customerResolver === null ? null : Closure::fromCallable($customerResolver);
        $this->applicationResolver = $applicationResolver === null ? null : Closure::fromCallable($applicationResolver);
        $this->failureHandler = $failureHandler === null ? null : Closure::fromCallable($failureHandler);
        $this->transport = $transport ?? $this->defaultTransport();

        $this->app->onEvent('flight.error', function (Throwable $exception): void {
            if ($this->active) {
                $this->capture($exception);
            }
        });
    }

    /** @param array<string, mixed> $params */
    public function before(array $params): void
    {
        $this->reset();

        if (! $this->shouldCapture()) {
            return;
        }

        $this->active = true;
        $this->startedAt = hrtime(true);
        $this->requestContext = new RequestContext;
        $this->routeParams = $params;
    }

    /** @param array<string, mixed> $params */
    public function after(array $params): void
    {
        if (! $this->active) {
            return;
        }

        $this->routeParams = $params;
        $this->capture();
    }

    public function context(): ?RequestContext
    {
        return $this->requestContext;
    }

    private function capture(?Throwable $exception = null): void
    {
        $this->active = false;

        try {
            $request = $this->app->request();
            $response = $this->app->response();
            $status = $response->status();
            assert(is_int($status));
            $metadata = $this->requestMetadata();
            if ($exception !== null) {
                $metadata['exception'] = $exception::class;
            }

            $this->transport->send([$this->events->create(new EventContext(
                service: $this->config->service,
                environment: $this->config->environment,
                method: $request->method,
                route: $this->route(),
                routeName: $this->routeName(),
                status: $exception === null ? $status : 500,
                durationMilliseconds: (int) round((hrtime(true) - $this->startedAt) / 1_000_000),
                requestBytes: max(0, $request->length),
                responseBytes: $this->contentLength($response->getHeader('Content-Length') ?? ''),
                customer: $this->resolveCustomer(),
                application: $this->resolveApplication(),
                apiVersion: $this->stringOrNull(Request::getHeader('X-Api-Version')),
                sdk: 'flightphp',
                sdkVersion: self::VERSION,
                release: $this->config->release,
                errorCode: $this->requestContext?->errorCode(),
                metadata: $this->metadata->sanitize($metadata, $this->config->metadataKeys),
            ))]);
        } catch (Throwable $failure) {
            if ($this->failureHandler === null) {
                return;
            }

            try {
                ($this->failureHandler)($failure);
            } catch (Throwable) {
                // Telemetry must never change the customer response.
            }
        }
    }

    private function shouldCapture(): bool
    {
        if (! $this->config->enabled || $this->config->apiKey === '') {
            return false;
        }

        $path = $this->requestPath();
        foreach ($this->config->paths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function resolveCustomer(): ?ApirelioCustomer
    {
        return $this->customerResolver === null
            ? null
            : ($this->customerResolver)($this->app, $this->routeParams);
    }

    private function resolveApplication(): ?ApirelioApplication
    {
        if ($this->applicationResolver === null) {
            return null;
        }

        $application = ($this->applicationResolver)($this->app, $this->routeParams);

        return is_string($application) ? new ApirelioApplication($application) : $application;
    }

    /** @return array<string, bool|float|int|string|null> */
    private function requestMetadata(): array
    {
        $metadata = $this->requestContext?->metadata() ?? [];
        foreach ($this->config->captureHeaders as $header) {
            $value = Request::getHeader($header);
            if ($value !== '') {
                $metadata['header.'.strtolower($header)] = mb_substr($value, 0, 500);
            }
        }

        return $metadata;
    }

    private function route(): string
    {
        $pattern = $this->app->router()->executedRoute?->pattern;
        if (is_string($pattern) && $pattern !== '') {
            return '/'.ltrim($pattern, '/');
        }

        $segments = explode('/', trim($this->requestPath(), '/'));
        $segments = array_map(static function (string $segment): string {
            if (
                ctype_digit($segment)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $segment) === 1
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $segment) === 1
            ) {
                return '{id}';
            }

            return $segment;
        }, $segments);

        return '/'.implode('/', $segments);
    }

    private function routeName(): ?string
    {
        return $this->stringOrNull($this->app->router()->executedRoute?->alias);
    }

    private function requestPath(): string
    {
        $path = parse_url($this->app->request()->url, PHP_URL_PATH);

        return '/'.ltrim(is_string($path) ? $path : '/', '/');
    }

    private function defaultTransport(): EventTransport
    {
        $transport = new HttpBatchTransport(
            new CurlIngestionClient,
            new TransportConfig(
                endpoint: $this->config->endpoint,
                apiKey: $this->config->apiKey,
                timeoutSeconds: $this->config->timeoutSeconds,
                connectTimeoutSeconds: $this->config->connectTimeoutSeconds,
            ),
        );

        return $this->config->bufferPath === null ? $transport : new FileBufferTransport(
            $transport,
            new BufferConfig(
                path: $this->config->bufferPath,
                batchSize: $this->config->batchSize,
                flushIntervalSeconds: $this->config->flushIntervalSeconds,
            ),
        );
    }

    private function contentLength(string $value): int
    {
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function reset(): void
    {
        $this->requestContext = null;
        $this->startedAt = 0;
        $this->active = false;
        $this->routeParams = [];
    }
}
