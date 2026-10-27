<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Programme extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function weeks()
    {
        return $this->hasMany(ProgrammeWeek::class)->orderBy('number');
    }

    public function logoUrl(): string
    {
        $logo = $this->settings['logo'] ?? null;

        return $logo && $logo !== 'Images/ICRC_Logo.png' ? asset('storage/'.$logo) : (asset('brand/ICRC_Logo.png').'?v=20261027d');
    }
}
