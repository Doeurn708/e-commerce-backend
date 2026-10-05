<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order_item extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price'
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'product_id' => 'integer',
            'quantity' => 'integer',
            'price' => 'float',
        ];
    }

    public function order():BelongsTo{
        return $this->belongsTo(Order::class);
    }

    public function product():BelongsTo{
        return $this->belongsTo(Product::class);
    }
}
