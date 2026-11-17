<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Selection extends Model
{
    protected $fillable = ['submission_key', 'name', 'items'];

    protected $casts = ['items' => 'array'];
}
