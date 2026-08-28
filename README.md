# Apirelio CakePHP SDK

[Documentation](https://apirelio.com/docs/php/cakephp) · [Live demo](https://apirelio.com/demo?framework=cakephp) · [Packagist](https://packagist.org/packages/apirelio/cakephp) · [Apirelio](https://apirelio.com)

[![Packagist Version](https://img.shields.io/packagist/v/apirelio/cakephp.svg)](https://packagist.org/packages/apirelio/cakephp)
[![Tests](https://github.com/pryznar/apirelio-cakephp/actions/workflows/tests.yml/badge.svg)](https://github.com/pryznar/apirelio-cakephp/actions/workflows/tests.yml)

Customer-aware API monitoring middleware for CakePHP 4.5 and 5.x. It captures route-level operational telemetry without request or response payloads and delegates the shared event contract, privacy filtering and delivery to `apirelio/php-core`.

[![Apirelio customer-level API dashboard](https://raw.githubusercontent.com/pryznar/apirelio-app/main/apps/frontend/public/img/apirelio-live-demo-dashboard.jpg)](https://apirelio.com/demo?framework=cakephp)

**[See the live customer-impact demo →](https://apirelio.com/demo?framework=cakephp)**

## Install in 30 seconds

```bash
composer require apirelio/cakephp:^0.1
```

```php
// src/Application.php
use Apirelio\CakePHP\ApirelioMiddleware;
use Apirelio\CakePHP\Config as ApirelioConfig;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;

public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    $middlewareQueue
        ->add(new ErrorHandlerMiddleware())
        ->add(new RoutingMiddleware($this))
        ->add(new ApirelioMiddleware(new ApirelioConfig(
            apiKey: (string) env('APIRELIO_API_KEY'),
            service: 'billing-api',
            environment: (string) env('APP_ENV', 'production'),
            release: env('APP_RELEASE') ?: null,
            paths: ['/api/*'],
            bufferPath: LOGS.'apirelio-events.ndjson',
        )));

    return $middlewareQueue;
}
```

Put Apirelio after `RoutingMiddleware` so it receives CakePHP's `_matchedRoute` template. Keep `ErrorHandlerMiddleware` before it so CakePHP can still render the original exception after Apirelio records its class.

## Identify the customer

```php
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;

$middleware = new ApirelioMiddleware(
    config: $config,
    customerResolver: static function ($request): ?ApirelioCustomer {
        $account = $request->getAttribute('identity')?->getOriginalData();

        return $account === null ? null : new ApirelioCustomer(
            id: (string) $account->id,
            name: $account->name,
            plan: $account->plan,
        );
    },
    applicationResolver: static fn ($request) => new ApirelioApplication(
        id: (string) $request->getAttribute('apiClient')->id,
    ),
);
```

## Add safe request context

```php
use Apirelio\CakePHP\RequestContext;

$context = $this->getRequest()->getAttribute(RequestContext::ATTRIBUTE);
$context?->addMetadata(['region' => 'eu-central']);
$context?->setErrorCode('PAYMENT_REQUIRED');
```

Only scalar metadata keys listed in `metadataKeys` are retained. Sensitive-looking keys are always rejected. Request bodies, response bodies, query strings, credentials, cookies, client IPs and exception messages are never captured.

## Example application

The [`example`](example) directory contains the complete middleware and controller snippets for a CakePHP API. Connect it to a project and then open the [live Apirelio demo](https://apirelio.com/demo?framework=cakephp) to see the resulting customer-level workflow.

## Requirements

- PHP 8.2+
- CakePHP 4.5 or 5.x
- ext-curl and ext-mbstring

## License

MIT
