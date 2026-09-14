<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public readonly string $occurredAt;

    public function __construct(
        public readonly int $flashSaleId,
        public readonly int $flashSaleItemId,
        public readonly int $remainingStock,
        public readonly string $reason = 'reserved',
        string $occurredAt = '',
    ) {
        $this->occurredAt = $occurredAt !== '' ? $occurredAt : now()->toISOString();
    }

    public function broadcastOn(): array
    {
        return [new Channel("flash-sale.{$this->flashSaleId}")];
    }

    public function broadcastAs(): string
    {
        return 'stock.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'flash_sale_id' => $this->flashSaleId,
            'flash_sale_item_id' => $this->flashSaleItemId,
            'remaining_stock' => $this->remainingStock,
            'reason' => $this->reason,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
