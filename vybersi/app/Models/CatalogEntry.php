<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogEntry extends Model
{
    public const KINDS = [
        'package' => 'Balíček',
        'service' => 'Služba',
        'category' => 'Kategorie služeb',
        'shipping' => 'Způsob doručení',
        'text' => 'Textová šablona',
    ];

    protected $fillable = ['kind', 'name', 'category_id', 'details'];

    protected $casts = ['details' => 'array'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    public static function options(string $kind): array
    {
        return static::query()->where('kind', $kind)->orderBy('name')->pluck('name', 'id')->all();
    }
}
