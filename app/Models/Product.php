<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'stock',
        'image',
        'image_url',
        'image_public_id',
    ];

    /**
     * PostgreSQL `numeric` columns come back as strings. Casting here keeps the
     * JSON API returning real numbers so the frontend never has to parse them.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'stock' => 'integer',
            'category_id' => 'integer',
        ];
    }


    public function category():BelongsTo{
        return $this->belongsTo(Category::class);
    }

    public  function orderItems():HasMany{
        return $this->hasMany(Order_item::class);
    }

    public function scopeInStock($query){
        return $query->where('stock', '>', 0);
    }
}
