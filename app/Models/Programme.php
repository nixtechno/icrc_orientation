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

    /** The programme timezone as people read it, e.g. "Abuja, Nigeria" rather than "Africa/Lagos". */
    public function timezoneLabel(): string
    {
        return ($this->timezone ?: 'Africa/Lagos') === 'Africa/Lagos' ? 'Abuja, Nigeria' : $this->timezone;
    }
}
