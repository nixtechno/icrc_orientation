<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function ($badge): void {
            $badge->collection_code ??= 'SCX-'.strtoupper(bin2hex(random_bytes(6)));
        });
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'generated_at' => 'datetime', 'printed_at' => 'datetime', 'collected_at' => 'datetime', 'materials_collected_at' => 'datetime'];
    }
}
