<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Gallery extends Model
{
    protected $fillable = ['title', 'description', 'mode', 'password', 'watermark', 'settings', 'expires_at', 'reminded_at'];

    protected $casts = ['password' => 'hashed', 'watermark' => 'boolean', 'settings' => 'array', 'expires_at' => 'datetime', 'reminded_at' => 'datetime'];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::creating(fn (Gallery $gallery) => $gallery->token = Str::random(48));
        static::deleted(fn (Gallery $gallery) => Storage::disk('local')->deleteDirectory($gallery->directory()));
    }

    public function option(string $path, mixed $default = null): mixed
    {
        return data_get($this->settings, $path, $default) ?? $default;
    }

    public function isOpen(): bool
    {
        return $this->option('status', 'open') === 'open' && ! $this->expires_at?->isPast();
    }

    public function downloadsAllowed(): bool
    {
        return $this->mode === 'delivery' && ! $this->option('disable_downloads');
    }

    public function directory(): string
    {
        return "galleries/{$this->token}";
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class);
    }
}
