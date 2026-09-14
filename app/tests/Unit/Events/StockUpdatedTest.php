<?php

namespace Tests\Unit\Events;

use App\Events\StockUpdated;
use Illuminate\Broadcasting\Channel;
use Tests\TestCase;

class StockUpdatedTest extends TestCase
{
    public function test_it_uses_the_expected_public_channel_and_payload(): void
    {
        $event = new StockUpdated(
            flashSaleId: 12,
            flashSaleItemId: 34,
            remainingStock: 7,
            reason: 'reserved',
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('flash-sale.12', $channels[0]->name);
        $this->assertSame('stock.updated', $event->broadcastAs());
        $payload = $event->broadcastWith();
        $this->assertSame(12, $payload['flash_sale_id']);
        $this->assertSame(34, $payload['flash_sale_item_id']);
        $this->assertSame(7, $payload['remaining_stock']);
        $this->assertSame('reserved', $payload['reason']);
        $this->assertNotEmpty($payload['occurred_at']);
    }
}
