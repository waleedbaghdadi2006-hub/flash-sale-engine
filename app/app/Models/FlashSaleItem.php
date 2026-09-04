<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlashSaleItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'flash_sale_id',
        'product_id',
        'sale_price',
        'quantity_limit',
        'quantity_sold',
        'version',
    ];

    protected $casts = [
        'flash_sale_id' => 'integer',
        'product_id' => 'integer',
        'sale_price' => 'decimal:2',
        'quantity_limit' => 'integer',
        'quantity_sold' => 'integer',
        'version' => 'integer',
    ];
    public function remainingStock(): int
{
    return $this->quantity_limit - $this->quantity_sold;
}

/**
 * Atomically increments quantity_sold if this row's version still
 * matches what we last read (optimistic lock). Returns false — without
 * throwing — if another process updated the row first, so the caller
 * can reload and retry.
 */
public function tryReserve(int $quantity): bool
{
    $updated = static::query()
        ->where('id', $this->id)
        ->where('version', $this->version)
        ->where('quantity_sold', '<=', $this->quantity_limit - $quantity)
        ->update([
            'quantity_sold' => $this->quantity_sold + $quantity,
            'version' => $this->version + 1,
        ]);

    if ($updated) {
        $this->quantity_sold += $quantity;
        $this->version += 1;
    }

    return (bool) $updated;
}

    public function flashSale(): BelongsTo
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
