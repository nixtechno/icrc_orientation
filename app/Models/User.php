<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Concerns\HasPublicSlug;
    public const ROLES = ['admin', 'facilitator', 'staff'];

    public const FACILITATOR_DEFAULT_PERMISSIONS = ['assessments', 'resources', 'announcements', 'qa_answer'];
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'profile',
        'photo_path',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'permissions' => 'array',
            'profile' => 'encrypted:array',
            'privacy_acknowledged_at' => 'datetime',
        ];
    }

    public function canManage(string $permission): bool
    {
        return $this->active && ($this->role === 'admin' || ($this->role === 'facilitator' && in_array($permission, $this->permissions ?? [], true)));
    }

    public function hasLockedInvitationEmail(): bool
    {
        return $this->exists && $this->role === 'facilitator'
            && \Illuminate\Support\Facades\DB::table('invitations')->where('user_id', $this->id)->exists();
    }

    public function canAnswerSession(ProgrammeSession $session): bool
    {
        return $this->canManage('qa') || ($this->active && in_array('qa_answer', $this->permissions ?? [], true) && Facilitator::where('user_id', $this->id)->whereHas('sessions', fn ($q) => $q->where('programme_sessions.id', $session->id))->exists());
    }

    public function programmes()
    {
        return $this->belongsToMany(Programme::class, 'enrolments');
    }
}
