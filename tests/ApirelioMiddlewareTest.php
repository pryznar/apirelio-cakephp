<?php

declare(strict_types=1);

namespace Apirelio\CakePHP\Tests;

use Apirelio\CakePHP\ApirelioMiddleware;
use Apirelio\CakePHP\Config;
use Apirelio\CakePHP\RequestContext;
use Apirelio\Core\Contracts\EventTransport;
use Apirelio\Core\Data\ApirelioApplication;
use Apirelio\Core\Data\ApirelioCustomer;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class ApirelioMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.encoding', 'UTF-8');
    }

    public function test_it_captures_a_normalized_cakephp_route_and_customer_context(): void
    {
        $transport = new RecordingTransport;
        $middleware = new ApirelioMiddleware(
            new Config(
                apiKey: 'apr_test',
                service: 'billing-api',
                environment: 'test',
                release: '2026.08.28.1',
                metadataKeys: ['region'],
            ),
            customerResolver: static fn (): ApirelioCustomer => new ApirelioCustomer('customer_42', 'Acme', 'growth'),
            applicationResolver: static fn (): ApirelioApplication => new ApirelioApplication('shopify', 'Shopify'),
            transport: $transport,
        );
        $request = (new ServerRequest(['url' => '/api/customers/123']))
            ->withAttribute('params', [
                '_matchedRoute' => '/api/customers/{id}',
                '_name' => 'customers:view',
            ])
            ->withHeader('X-Api-Version', '2026-08');
        $response = $middleware->process($request, new CallbackHandler(
            static function (ServerRequestInterface $request): ResponseInterface {
                $context = $request->getAttribute(RequestContext::ATTRIBUTE);
                self::assertInstanceOf(RequestContext::class, $context);
                $context->addMetadata(['region' => 'eu-central', 'password' => 'never-store']);
                $context->setErrorCode('PAYMENT_REQUIRED');

                return (new Response)->withStatus(402)->withHeader('Content-Length', '12');
            },
        ));

        self::assertSame(402, $response->getStatusCode());
        self::assertCount(1, $transport->events);
        self::assertSame('/api/customers/{id}', $transport->events[0]['route']);
        self::assertSame('customers:view', $transport->events[0]['route_name']);
        self::assertSame('customer_42', $transport->events[0]['customer_id']);
        self::assertSame('shopify', $transport->events[0]['application_id']);
        self::assertSame('PAYMENT_REQUIRED', $transport->events[0]['error_code']);
        self::assertSame(['region' => 'eu-central'], $transport->events[0]['metadata']);
        self::assertSame('cakephp', $transport->events[0]['sdk']);
        self::assertSame('0.1.0', $transport->events[0]['sdk_version']);
        self::assertSame(12, $transport->events[0]['response_bytes']);
    }

    public function test_it_records_and_rethrows_an_unhandled_exception_without_its_message(): void
    {
        $transport = new RecordingTransport;
        $middleware = new ApirelioMiddleware(new Config(apiKey: 'apr_test'), transport: $transport);
        $request = new ServerRequest(['url' => '/api/fail']);

        try {
            $middleware->process($request, new CallbackHandler(
                static fn (): never => throw new RuntimeException('private detail'),
            ));
            self::fail('The application exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('private detail', $exception->getMessage());
        }

        self::assertCount(1, $transport->events);
        self::assertSame(500, $transport->events[0]['status']);
        self::assertSame(['exception' => RuntimeException::class], $transport->events[0]['metadata']);
        self::assertArrayNotHasKey('message', $transport->events[0]['metadata']);
    }

    public function test_telemetry_failure_never_changes_the_customer_response(): void
    {
        $failures = [];
        $transport = new class implements EventTransport
        {
            public function send(array $events): void
            {
                throw new RuntimeException('ingestion unavailable');
            }
        };
        $middleware = new ApirelioMiddleware(
            new Config(apiKey: 'apr_test'),
            transport: $transport,
            failureHandler: static function (\Throwable $failure) use (&$failures): void {
                $failures[] = $failure->getMessage();
            },
        );
        $response = $middleware->process(
            new ServerRequest(['url' => '/api/health']),
            new CallbackHandler(static fn (): ResponseInterface => (new Response)->withStatus(204)),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['ingestion unavailable'], $failures);
    }

    public function test_it_skips_unmatched_paths_and_normalizes_dynamic_fallback_segments(): void
    {
        $transport = new RecordingTransport;
        $middleware = new ApirelioMiddleware(
            new Config(apiKey: 'apr_test', paths: ['/api/*']),
            transport: $transport,
        );
        $handler = new CallbackHandler(static fn (): ResponseInterface => new Response);

        $middleware->process(new ServerRequest(['url' => '/health']), $handler);
        $middleware->process(new ServerRequest(['url' => '/api/customers/123']), $handler);

        self::assertCount(1, $transport->events);
        self::assertSame('/api/customers/{id}', $transport->events[0]['route']);
    }
}

final class CallbackHandler implements RequestHandlerInterface
{
    /** @param callable(ServerRequestInterface): ResponseInterface $callback */
    public function __construct(private $callback) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->callback)($request);
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
