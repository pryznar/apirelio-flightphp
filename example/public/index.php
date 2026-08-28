<?php

declare(strict_types=1);

use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;
use Apirelio\FlightPHP\ApirelioMiddleware;
use Apirelio\FlightPHP\Config;
use flight\Engine;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = new Engine;
$apirelio = new ApirelioMiddleware(
    $app,
    new Config(
        apiKey: (string) getenv('APIRELIO_API_KEY'),
        endpoint: getenv('APIRELIO_ENDPOINT') ?: 'https://apirelio.com',
        service: getenv('APIRELIO_SERVICE') ?: 'flightphp-example',
        environment: getenv('APIRELIO_ENVIRONMENT') ?: 'development',
        release: getenv('APIRELIO_RELEASE') ?: null,
        paths: ['/api/*'],
        bufferPath: dirname(__DIR__).'/storage/apirelio-events.ndjson',
    ),
    customerResolver: static fn (Engine $app, array $params): ApirelioCustomer => new ApirelioCustomer(
        id: 'customer_42',
        name: 'Acme',
        plan: 'growth',
    ),
    applicationResolver: static fn (): ApirelioApplication => new ApirelioApplication('public-api'),
);

$app->group('/api', function () use ($app, $apirelio): void {
    $app->get('/invoices/@id', function (string $id) use ($app, $apirelio): void {
        $apirelio->context()?->addMetadata(['region' => 'eu-central']);
        $app->json(['id' => $id, 'status' => 'paid']);
    }, false, 'invoices:view');
}, [$apirelio]);

$app->start();
