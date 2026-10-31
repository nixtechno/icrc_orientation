<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileService
{
    public const PERSONAL = ['first_name', 'middle_name', 'surname', 'preferred_name', 'accommodation', 'emergency_name', 'emergency_relationship', 'emergency_phone', 'programme_information'];

    public const EMPLOYMENT = ['resumption_date', 'duty_location'];

    /** Employment details assigned by administrators; staff do not fill these in at activation. */
    public const ASSIGNED = ['staff_number', 'department', 'designation', 'group_name', 'resumption_date', 'duty_location'];
    public const FACILITATOR_PERSONAL = ['first_name', 'middle_name', 'surname', 'preferred_name', 'organisation', 'alternative_phone'];

    public const CONFIGURABLE = ['phone', 'department', 'designation', 'photo', 'resumption_date', 'duty_location', 'emergency_name', 'emergency_relationship', 'emergency_phone'];

    public const IMPORT_COLUMNS = ['first_name', 'middle_name', 'surname', 'preferred_name', 'email', 'phone', 'staff_number', 'department', 'designation', 'resumption_date', 'duty_location', 'group_name'];
    public const FACILITATOR_IMPORT_COLUMNS = ['first_name', 'middle_name', 'surname', 'preferred_name', 'email', 'phone', 'alternative_phone', 'organisation', 'department', 'designation'];

    public static function importColumns(string $role): array
    {
        return $role === 'facilitator' ? self::FACILITATOR_IMPORT_COLUMNS : self::IMPORT_COLUMNS;
    }

    public function rules(?User $user = null, bool $complete = false, ?string $role = null): array
    {
        $rules = ['first_name' => [$complete ? 'required' : 'nullable', 'string', 'max:100'], 'surname' => [$complete ? 'required' : 'nullable', 'string', 'max:100'], 'name' => 'nullable|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)], 'phone' => 'nullable|string|max:40', 'staff_number' => ['nullable', 'string', 'max:100', Rule::unique('users')->ignore($user?->id)], 'department' => 'nullable|string|max:255', 'designation' => 'nullable|string|max:255', 'group_name' => 'nullable|string|max:255', 'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:5120', 'resumption_date' => 'nullable|date', 'duty_location' => 'nullable|string|max:255'];
        foreach (array_diff(self::PERSONAL, ['first_name', 'surname']) as $f) {
            $rules[$f] = 'nullable|string|max:'.(in_array($f, ['accommodation', 'programme_information']) ? 2000 : 255);
        }
        if (($role ?? $user?->role) === 'facilitator') {
            $rules = array_intersect_key($rules, array_flip(['first_name', 'middle_name', 'surname', 'preferred_name', 'name', 'email', 'phone', 'photo', 'department', 'designation']));
            $rules['organisation'] = 'nullable|string|max:255';
            $rules['alternative_phone'] = 'nullable|string|max:40';
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

    public function assign(User $u, array $data, bool $official = true, ?string $role = null): void
    {
        $p = $u->profile ?? [];
        $isFacilitator = ($role ?? $u->role) === 'facilitator';
        foreach ($isFacilitator ? self::FACILITATOR_PERSONAL : [...self::PERSONAL, ...($official ? self::EMPLOYMENT : [])] as $f) {
            if (array_key_exists($f, $data)) {
                $p[$f] = $data[$f];
            }
        }$u->profile = $p;
        if (! empty($p['first_name']) && ! empty($p['surname'])) {
            $u->name = trim(implode(' ', array_filter([$p['first_name'], $p['middle_name'] ?? null, $p['surname']])));
        } elseif (! empty($data['name'])) {
            $u->name = $data['name'];
        }foreach ($isFacilitator ? ['phone', 'department', 'designation', ...($official ? ['email'] : [])] : ['phone', ...($official ? ['email', 'staff_number', 'department', 'designation', 'group_name'] : [])] as $f) {
            if (array_key_exists($f, $data)) {
                $u->$f = $data[$f];
            }
        }
    }

    public function syncFacilitator(User $user): void
    {
        if ($user->role !== 'facilitator') return;
        $contact = [
            'department' => $user->department,
            'job_title' => $user->designation,
            'phone' => $user->phone,
            'email' => $user->email,
        ];
        foreach (['organisation', 'alternative_phone'] as $field) {
            if (array_key_exists($field, $user->profile ?? [])) $contact[$field] = $user->profile[$field];
        }
        \App\Models\Facilitator::where('user_id', $user->id)->update($contact);
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
