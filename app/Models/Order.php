<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['user_id','address','time','status'];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }

    public function meal_orders()
    {
        return $this->hasMany(MealOrder::class,'order_id');
    }
}
