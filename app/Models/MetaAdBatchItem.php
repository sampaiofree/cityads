<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAdBatchItem extends Model
{
    protected $fillable = [
        'meta_ad_batch_id',
        'city_id',
        'city_name',
        'state_name',
        'meta_city_key',
        'ad_set_id',
        'ad_id',
        'ad_creative_id',
        'image_hash',
        'status',
        'error_message',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MetaAdBatch::class, 'meta_ad_batch_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
