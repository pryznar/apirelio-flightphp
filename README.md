# Apirelio FlightPHP SDK

[Documentation](https://apirelio.com/docs/php/flightphp) · [Live demo](https://apirelio.com/demo?framework=flightphp) · [Packagist](https://packagist.org/packages/apirelio/flightphp) · [Apirelio](https://apirelio.com)

[![Packagist Version](https://img.shields.io/packagist/v/apirelio/flightphp.svg)](https://packagist.org/packages/apirelio/flightphp)
[![Tests](https://github.com/pryznar/apirelio-flightphp/actions/workflows/tests.yml/badge.svg)](https://github.com/pryznar/apirelio-flightphp/actions/workflows/tests.yml)

Customer-aware API monitoring middleware for FlightPHP 3. It captures route-level operational telemetry without request or response payloads and delegates the shared event contract, privacy filtering and delivery to `apirelio/php-core`.

[![Apirelio customer-level API dashboard](https://raw.githubusercontent.com/pryznar/apirelio-app/main/apps/frontend/public/img/apirelio-live-demo-dashboard.jpg)](https://apirelio.com/demo?framework=flightphp)

**[See the live customer-impact demo →](https://apirelio.com/demo?framework=flightphp)**

## Install in 30 seconds

```bash
composer require apirelio/flightphp:^1.0
```

```php
use Apirelio\FlightPHP\ApirelioMiddleware;
use Apirelio\FlightPHP\Config as ApirelioConfig;
use flight\Engine;

$app = new Engine;
$apirelio = new ApirelioMiddleware($app, new ApirelioConfig(
    apiKey: (string) getenv('APIRELIO_API_KEY'),
    service: 'billing-api',
    environment: getenv('APP_ENV') ?: 'production',
    release: getenv('APP_RELEASE') ?: null,
    paths: ['/api/*'],
    bufferPath: __DIR__.'/../storage/apirelio-events.ndjson',
));

$app->group('/api', function () use ($app): void {
    $app->get('/invoices/@id', [InvoiceController::class, 'show'], false, 'invoices:view');
}, [$apirelio]);

$app->start();
```

Flight executes `before()` before the route and `after()` afterwards. Apirelio reads the matched `Route::$pattern` and alias from `Router::$executedRoute`; an internal `flight.error` listener records the exception class when the route fails.

## Identify the customer

```php
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;

$apirelio = new ApirelioMiddleware(
    app: $app,
    config: $config,
    customerResolver: static function (Engine $app, array $params): ?ApirelioCustomer {
        $account = $app->account();

        return $account === null ? null : new ApirelioCustomer(
            id: (string) $account->id,
            name: $account->name,
            plan: $account->plan,
        );
    },
    applicationResolver: static fn (Engine $app): ApirelioApplication => new ApirelioApplication(
        id: (string) $app->apiClient()->id,
    ),
);
```

## Add safe request context

```php
$apirelio->context()?->addMetadata(['region' => 'eu-central']);
$apirelio->context()?->setErrorCode('PAYMENT_REQUIRED');
```

Only scalar metadata keys listed in `metadataKeys` are retained. Sensitive-looking keys are always rejected. Request bodies, response bodies, query strings, credentials, cookies, client IPs and exception messages are never captured.

## Example application

The [`example`](example) directory contains a complete FlightPHP API bootstrap. Connect it to a project and then open the [live Apirelio demo](https://apirelio.com/demo?framework=flightphp) to see the resulting customer-level workflow.

## Requirements

- PHP 8.2+
- FlightPHP 3.10+
- ext-curl and ext-mbstring

## License

MIT
