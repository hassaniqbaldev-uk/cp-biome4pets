<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

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
