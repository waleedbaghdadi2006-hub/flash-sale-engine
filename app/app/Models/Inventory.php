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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
