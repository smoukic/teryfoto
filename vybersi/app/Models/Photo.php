<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    protected $fillable = ['key', 'filename', 'mime'];

    public function directory(): string
    {
        return $this->belongsTo(Gallery::class, 'gallery_id')->firstOrFail()->directory()."/{$this->key}";
    }

    protected static function booted(): void
    {
        static::deleted(fn (Photo $photo) => Storage::disk('local')->deleteDirectory($photo->directory()));
    }
}
