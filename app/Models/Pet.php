<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pet extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'breed',
        'date_of_birth',
        'sex',
        'diet',
        'health_notes',
        'shopify_pet_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function tests(): HasMany
    {
        return $this->hasMany(Test::class);
    }
}
