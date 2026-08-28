<?php

declare(strict_types=1);

namespace App\Controller;

use Apirelio\CakePHP\RequestContext;

final class InvoicesController extends AppController
{
    public function create(): void
    {
        $context = $this->getRequest()->getAttribute(RequestContext::ATTRIBUTE);
        $context?->addMetadata(['region' => 'eu-central']);
        $context?->setErrorCode('PAYMENT_REQUIRED');

        $this->response = $this->response->withStatus(402);
    }
}
