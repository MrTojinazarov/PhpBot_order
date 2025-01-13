<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meal extends Model
{
    protected $fillable = ['name', 'category_id', 'price', 'image'];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function meal_orders()
    {
        return $this->hasMany(MealOrder::class, 'meal_id');
    }
}
