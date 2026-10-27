<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileService
{
    public const PERSONAL = ['first_name', 'middle_name', 'surname', 'preferred_name', 'accommodation', 'emergency_name', 'emergency_relationship', 'emergency_phone', 'programme_information'];

    public const EMPLOYMENT = ['resumption_date', 'duty_location'];

    public const CONFIGURABLE = ['phone', 'department', 'designation', 'photo', 'resumption_date', 'duty_location', 'emergency_name', 'emergency_relationship', 'emergency_phone'];

    public const IMPORT_COLUMNS = ['first_name', 'middle_name', 'surname', 'preferred_name', 'email', 'phone', 'staff_number', 'department', 'designation', 'resumption_date', 'duty_location', 'group_name'];

    public function rules(?User $user = null, bool $complete = false): array
    {
        $rules = ['first_name' => [$complete ? 'required' : 'nullable', 'string', 'max:100'], 'surname' => [$complete ? 'required' : 'nullable', 'string', 'max:100'], 'name' => 'nullable|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)], 'phone' => 'nullable|string|max:40', 'staff_number' => ['nullable', 'string', 'max:100', Rule::unique('users')->ignore($user?->id)], 'department' => 'nullable|string|max:255', 'designation' => 'nullable|string|max:255', 'group_name' => 'nullable|string|max:255', 'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', 'resumption_date' => 'nullable|date', 'duty_location' => 'nullable|string|max:255'];
        foreach (array_diff(self::PERSONAL, ['first_name', 'surname']) as $f) {
            $rules[$f] = 'nullable|string|max:'.(in_array($f, ['accommodation', 'programme_information']) ? 2000 : 255);
        }
        if ($complete) {
            $rules['phone'] = 'required|string|max:40';
            foreach (Access::programme()->settings['required_profile_fields'] ?? [] as $f) {
                if (isset($rules[$f]) && is_string($rules[$f])) {
                    $rules[$f] = str_replace('nullable', 'required', $rules[$f]);
                }
            }
        }

return $rules;
    }

    public function validate(Request $r, ?User $u = null, bool $complete = false): array
    {
        return $r->validate($this->rules($u, $complete));
    }

    public function assign(User $u, array $data, bool $official = true): void
    {
        $p = $u->profile ?? [];
        foreach ([...self::PERSONAL, ...($official ? self::EMPLOYMENT : [])] as $f) {
            if (array_key_exists($f, $data)) {
                $p[$f] = $data[$f];
            }
        }$u->profile = $p;
        if (! empty($p['first_name']) && ! empty($p['surname'])) {
            $u->name = trim(implode(' ', array_filter([$p['first_name'], $p['middle_name'] ?? null, $p['surname']])));
        } elseif (! empty($data['name'])) {
            $u->name = $data['name'];
        }foreach (['phone', ...($official ? ['email', 'staff_number', 'department', 'designation', 'group_name'] : [])] as $f) {
            if (array_key_exists($f, $data)) {
                $u->$f = $data[$f];
            }
        }
    }

    public function photo(Request $r, User $u): void
    {
        if ($r->hasFile('photo')) {
            $u->photo_path = $r->file('photo')->store('profile-photos', 'local');
        }
    }

    public static function invitationStatus(User $u, $i): string
    {
        if ($u->active) {
            return 'Activated';
        }if (! $i) {
            return 'Pending';
        }if ($i->revoked_at) {
            return 'Revoked';
        }if ($i->used_at) {
            return 'Activated';
        }

return now()->gte($i->expires_at) ? 'Expired' : 'Sent';
    }
}
