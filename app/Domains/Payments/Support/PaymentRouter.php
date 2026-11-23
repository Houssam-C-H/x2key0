<?php

declare(strict_types=1);

namespace App\Domains\Payments\Support;

use App\Domains\Payments\Contracts\PaymentProvider;
use App\Domains\Payments\Models\PaymentMethod;
use App\Shared\Money\Amount;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Picks the PSP driver for a payment method from the catalog (spec 9.1). Drivers are registered by code in
 * `config('x2key.payments.providers')`; a method whose provider is unknown or refuses (country, amount,
 * environment) is not offered.
 */
final class PaymentRouter
{
    public function __construct(private readonly Container $container) {}

    /**
     * Methods valid for this buyer country, currency and PSP amount, in catalog order.
     *
     * @return list<PaymentMethod>
     */
    public function available(?string $country, string $currency, int $amount): array
    {
        return array_values(PaymentMethod::query()->enabled()->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (PaymentMethod $m): bool => $m->accepts($country, $currency, $amount) && $this->providerOrNull($m)?->supports($m->code, $country, Amount::ofMinor($amount, $currency)) === true)
            ->all());
    }

    public function provider(string $code): PaymentProvider
    {
        $class = config("x2key.payments.providers.{$code}");

        if (! is_string($class) || ! is_a($class, PaymentProvider::class, true)) {
            throw new InvalidArgumentException("Unknown payment provider [{$code}].");
        }

        $provider = $this->container->make($class);

        if (! $provider instanceof PaymentProvider) {
            throw new InvalidArgumentException("Provider [{$code}] does not implement PaymentProvider.");
        }

        return $provider;
    }

    private function providerOrNull(PaymentMethod $method): ?PaymentProvider
    {
        try {
            return $this->provider($method->provider);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
