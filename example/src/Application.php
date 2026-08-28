<?php

declare(strict_types=1);

namespace App;

use Apirelio\CakePHP\ApirelioMiddleware;
use Apirelio\CakePHP\Config as ApirelioConfig;
use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;

final class Application extends BaseApplication
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))
            ->add(new RoutingMiddleware($this))
            ->add(new ApirelioMiddleware(new ApirelioConfig(
                apiKey: (string) env('APIRELIO_API_KEY'),
                endpoint: (string) env('APIRELIO_ENDPOINT', 'https://apirelio.com'),
                service: (string) env('APIRELIO_SERVICE', 'cakephp-example'),
                environment: (string) env('APIRELIO_ENVIRONMENT', 'development'),
                release: env('APIRELIO_RELEASE') ?: null,
                paths: ['/api/*'],
                bufferPath: LOGS.'apirelio-events.ndjson',
            )));

        return $middlewareQueue;
    }
}

