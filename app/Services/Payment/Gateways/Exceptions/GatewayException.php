<?php

namespace App\Services\Payment\Gateways\Exceptions;

class GatewayException extends \RuntimeException
{
    private string $gatewayName;
    private array $context;

    public function __construct(string $message, string $gatewayName, array $context = [])
    {
        parent::__construct($message);

        $this->gatewayName = $gatewayName;
        $this->context = $context;
    }

    public function getGatewayName(): string
    {
        return $this->gatewayName;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
