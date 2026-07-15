<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
   protected $fillable = [
    'name',
    'wa_name',
    'phone',
    'status',
    'last_chat_at'
];

    protected $casts = [
        'last_chat_at' => 'datetime',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}