<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupertrendAlert extends Model
{
    protected $guarded = [];

    protected $casts = ['acknowledged_at' => 'datetime'];
}
