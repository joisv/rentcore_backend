<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_id',
        'duration_unit',
        'duration_value',
        'duration_hours',
        'price',
        'extension_hourly_rate',
        'is_active',
    ];

    protected $casts = [
        'duration_value' => 'integer',
        'duration_hours' => 'integer',
        'price' => 'decimal:2',
        'extension_hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'model_id');
    }
}
