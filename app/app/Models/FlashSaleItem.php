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

    public function flashSale(): BelongsTo
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
