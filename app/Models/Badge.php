<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'generated_at' => 'datetime', 'printed_at' => 'datetime'];
    }
}
