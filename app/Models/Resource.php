<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];
}
