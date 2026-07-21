<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\Exceptions\GatewayException;

class GatewayManager
{
    private array $registry = [
        'pesapal' => \App\Services\Payment\Gateways\PesaPalGateway::class,
    ];

    private array $resolved = [];

    public function driver(string $name): PaymentGatewayInterface
    {
        $name = strtolower($name);

        if (!isset($this->registry[$name])) {
            throw new GatewayException(
                "Payment gateway '{$name}' is not registered. Available: " .
                implode(', ', array_keys($this->registry)),
                $name
            );
        }

        if (!isset($this->resolved[$name])) {
            $this->resolved[$name] = app($this->registry[$name]);
        }

        return $this->resolved[$name];
    }

    public function available(): array
    {
        return collect(array_keys($this->registry))
            ->filter(fn(string $name) => $this->driver($name)->isEnabled())
            ->values()
            ->toArray();
    }

    public function extend(string $name, string $driverClass): void
    {
        $this->registry[strtolower($name)] = $driverClass;
        unset($this->resolved[strtolower($name)]);
    }

    public function registered(): array
    {
        return array_keys($this->registry);
    }
}
