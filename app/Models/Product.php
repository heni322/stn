<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'site_id',
        'category_id',
    ];

    // A product belongs to a site
    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    // A product belongs to a category
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Product.php
    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

}
