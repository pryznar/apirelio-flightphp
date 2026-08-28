<?php

declare(strict_types=1);

namespace Apirelio\FlightPHP\Tests;

use Apirelio\Core\Contracts\EventTransport;
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;
use Apirelio\FlightPHP\ApirelioMiddleware;
use Apirelio\FlightPHP\Config;
use Apirelio\FlightPHP\RequestContext;
use flight\core\EventDispatcher;
use flight\Engine;
use flight\net\Route;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApirelioMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EventDispatcher::resetInstance();
        $_SERVER['HTTP_X_API_VERSION'] = '2026-08';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_API_VERSION']);
        EventDispatcher::resetInstance();
        parent::tearDown();
    }

    public function test_it_captures_a_flight_route_alias_and_customer_context(): void
    {
        $transport = new RecordingTransport;
        $app = $this->app('/api/customers/123', 'POST', 24);
        $app->router()->executedRoute = new Route(
            '/api/customers/@id',
            static fn (): null => null,
            ['POST'],
            false,
            'customers:view',
        );
        $middleware = new ApirelioMiddleware(
            $app,
            new Config(
                apiKey: 'apr_test',
                service: 'billing-api',
                environment: 'test',
                release: '2026.08.28.1',
                metadataKeys: ['region'],
            ),
            customerResolver: static fn (Engine $app, array $params): ApirelioCustomer => new ApirelioCustomer(
                'customer_'.$params['id'],
                'Acme',
                'growth',
            ),
            applicationResolver: static fn (): ApirelioApplication => new ApirelioApplication('shopify', 'Shopify'),
            transport: $transport,
        );

        $middleware->before(['id' => '42']);
        $context = $middleware->context();
        self::assertInstanceOf(RequestContext::class, $context);
        $context->addMetadata(['region' => 'eu-central', 'password' => 'never-store']);
        $context->setErrorCode('PAYMENT_REQUIRED');
        $response = $app->response();
        $response->status(402);
        $response->header('Content-Length', '12');
        $middleware->after(['id' => '42']);

        self::assertCount(1, $transport->events);
        self::assertSame('/api/customers/@id', $transport->events[0]['route']);
        self::assertSame('customers:view', $transport->events[0]['route_name']);
        self::assertSame('customer_42', $transport->events[0]['customer_id']);
        self::assertSame('shopify', $transport->events[0]['application_id']);
        self::assertSame('PAYMENT_REQUIRED', $transport->events[0]['error_code']);
        self::assertSame(['region' => 'eu-central'], $transport->events[0]['metadata']);
        self::assertSame('flightphp', $transport->events[0]['sdk']);
        self::assertSame('1.0.0', $transport->events[0]['sdk_version']);
        self::assertSame('2026-08', $transport->events[0]['api_version']);
        self::assertSame(24, $transport->events[0]['request_bytes']);
        self::assertSame(12, $transport->events[0]['response_bytes']);
    }

    public function test_it_records_a_flight_error_without_its_message(): void
    {
        $transport = new RecordingTransport;
        $app = $this->app('/api/fail');
        $app->router()->executedRoute = new Route('/api/fail', static fn (): null => null, ['GET'], false, 'fail');
        $middleware = new ApirelioMiddleware($app, new Config(apiKey: 'apr_test'), transport: $transport);

        $middleware->before([]);
        $app->triggerEvent('flight.error', new RuntimeException('private detail'));

        self::assertCount(1, $transport->events);
        self::assertSame(500, $transport->events[0]['status']);
        self::assertSame(['exception' => RuntimeException::class], $transport->events[0]['metadata']);
        self::assertArrayNotHasKey('message', $transport->events[0]['metadata']);
        self::assertNull($middleware->context()?->metadata()['message'] ?? null);
    }

    public function test_telemetry_failure_never_changes_flight_execution(): void
    {
        $failures = [];
        $transport = new class implements EventTransport
        {
            public function send(array $events): void
            {
                throw new RuntimeException('ingestion unavailable');
            }
        };
        $app = $this->app('/api/health');
        $middleware = new ApirelioMiddleware(
            $app,
            new Config(apiKey: 'apr_test'),
            transport: $transport,
            failureHandler: static function (\Throwable $failure) use (&$failures): void {
                $failures[] = $failure->getMessage();
            },
        );

        $middleware->before([]);
        $app->response()->status(204);
        $middleware->after([]);

        self::assertSame(204, $app->response()->status());
        self::assertSame(['ingestion unavailable'], $failures);
    }

    public function test_it_skips_unmatched_paths_and_normalizes_dynamic_fallback_segments(): void
    {
        $transport = new RecordingTransport;
        $healthApp = $this->app('/health');
        $health = new ApirelioMiddleware($healthApp, new Config(apiKey: 'apr_test'), transport: $transport);
        $health->before([]);
        $health->after([]);

        $apiApp = $this->app('/api/customers/123?debug=1');
        $api = new ApirelioMiddleware($apiApp, new Config(apiKey: 'apr_test'), transport: $transport);
        $api->before([]);
        $api->after([]);

        self::assertCount(1, $transport->events);
        self::assertSame('/api/customers/{id}', $transport->events[0]['route']);
    }

    /** @return Engine<object> */
    private function app(string $url, string $method = 'GET', int $length = 0): Engine
    {
        $app = new Engine;
        $request = $app->request();
        $request->url = $url;
        $request->base = '';
        $request->method = $method;
        $request->length = $length;

        return $app;
    }
}

final class RecordingTransport implements EventTransport
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function send(array $events): void
    {
        $this->events = array_merge($this->events, $events);
    }
}
