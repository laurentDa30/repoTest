<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialPost extends Model
{
    protected $fillable = ['platform', 'post_id', 'image_url', 'caption', 'post_url', 'posted_at'];

    protected $casts = [
        'posted_at' => 'datetime',
    ];
}
