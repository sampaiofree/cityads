<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaConnection extends Model
{
    protected $fillable = [
        'user_id',
        'access_token',
        'token_expires_at',
        'app_id',
        'app_secret',
        'ad_account_id',
        'page_id',
        'instagram_actor_id',
        'pixel_id',
        'last_synced_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'app_secret' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
