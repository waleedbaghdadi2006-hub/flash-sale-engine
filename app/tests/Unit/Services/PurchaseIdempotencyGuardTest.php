<?php

namespace Tests\Unit\Services;

use App\Services\PurchaseIdempotencyGuard;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PurchaseIdempotencyGuardTest extends TestCase
{
    private PurchaseIdempotencyGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new PurchaseIdempotencyGuard();
    }

    public function test_same_purchase_intent_is_rejected_until_the_guard_is_released(): void
    {
        $args = [
            'userId' => 101,
            'flashSaleItemId' => 900001,
            'quantity' => 2,
            'shippingAddressId' => 501,
            'billingAddressId' => 601,
        ];

        $this->assertTrue($this->guard->tryAcquire(...$args));
        $this->assertFalse($this->guard->tryAcquire(...$args));

        $this->guard->release(...$args);

        $this->assertTrue($this->guard->tryAcquire(...$args));
    }

    public function test_different_purchase_intents_do_not_collide(): void
    {
        $base = [
            'userId' => 101,
            'flashSaleItemId' => 900001,
            'quantity' => 2,
            'shippingAddressId' => 501,
            'billingAddressId' => 601,
        ];

        $this->assertTrue($this->guard->tryAcquire(...$base));

        $differentQuantity = $base;
        $differentQuantity['quantity'] = 3;

        $differentAddress = $base;
        $differentAddress['shippingAddressId'] = 502;

        $this->assertTrue($this->guard->tryAcquire(...$differentQuantity));
        $this->assertTrue($this->guard->tryAcquire(...$differentAddress));
    }

    public function test_guard_uses_the_configured_ttl(): void
    {
        config(['flash_sale.purchase_idempotency_ttl_seconds' => 7]);

        Cache::shouldReceive('add')
            ->once()
            ->withArgs(function (string $key, array $value, $expiration): bool {
                return str_starts_with($key, 'flash_sale_purchase:idempotency:')
                    && isset($value['created_at'])
                    && $expiration instanceof \DateTimeInterface
                    && now()->diffInSeconds($expiration, false) <= 7
                    && now()->diffInSeconds($expiration, false) >= 5;
            })
            ->andReturnTrue();

        $this->assertTrue($this->guard->tryAcquire(101, 900001, 1, 501, null));
    }
}
