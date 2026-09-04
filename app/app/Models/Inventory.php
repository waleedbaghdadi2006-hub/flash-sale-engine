<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'product_id',
        'quantity_available',
        'quantity_reserved',
        'version',
        'last_restocked_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'quantity_available' => 'integer',
        'quantity_reserved' => 'integer',
        'version' => 'integer',
        'last_restocked_at' => 'datetime',
    ];
    public function tryReserve(int $quantity): bool
{
    $updated = static::query()
        ->where('id', $this->id)
        ->where('version', $this->version)
        ->where('quantity_available', '>=', $quantity)
        ->update([
            'quantity_available' => $this->quantity_available - $quantity,
            'quantity_reserved' => $this->quantity_reserved + $quantity,
            'version' => $this->version + 1,
        ]);

    if ($updated) {
        $this->quantity_available -= $quantity;
        $this->quantity_reserved += $quantity;
        $this->version += 1;
    }

    return (bool) $updated;
}

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
