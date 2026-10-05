<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'total_price',
        'status',
        'shipping_address',
        'latitude',
        'longitude',
        'phone',
        'city',
        'province',
        'postal_code',
        'payment_method',

    ];

    /**
     * `numeric` money columns arrive as strings from PostgreSQL; cast them so
     * the API always answers with JSON numbers.
     *
     * The coordinates are cast to float for the same reason. `decimal:7` would
     * hand the SPA a string, which then has to be parsed before it can be sent
     * straight back on a repeat order.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'total_price' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user():BelongsTo{
        return $this->belongsTo(User::class);
    }

    public function orderItems():HasMany{
        return $this->hasMany(Order_item::class);
    }

    public function payment():HasOne{
        return $this->hasOne(Payment::class);
    }


}
