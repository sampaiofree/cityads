<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaAdBatch extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'objective',
        'ad_account_id',
        'page_id',
        'instagram_actor_id',
        'pixel_id',
        'start_at',
        'url_template',
        'title_template',
        'body_template',
        'image_path',
        'auto_activate',
        'daily_budget_cents',
        'status',
        'total_items',
        'processed_items',
        'success_count',
        'error_count',
        'meta_campaign_id',
        'settings',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'auto_activate' => 'boolean',
        'settings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MetaAdBatchItem::class);
    }
}
