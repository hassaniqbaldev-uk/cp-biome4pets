<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'shopify_client_id',
    ];

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    public function pets()
    {
        return $this->hasMany(Pet::class);
    }
}
