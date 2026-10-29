<?php

namespace App\Support;

use Illuminate\Support\Str;

class PublicSlug
{
    public const TABLES = ['users', 'activities', 'programme_sessions', 'submissions', 'facilitators', 'programme_days', 'timetable_proposals', 'session_questions', 'session_answers', 'attendance_device_requests', 'resources', 'announcements', 'badges'];

    public static function make(string $table, array $attributes): string
    {
        $label = match ($table) {
            'users', 'facilitators' => $attributes['name'] ?? 'profile',
            'activities', 'programme_sessions', 'resources', 'announcements' => $attributes['title'] ?? 'session',
            'programme_days' => 'programme-day-'.($attributes['number'] ?? ''),
            'submissions' => 'attempt',
            'session_questions' => 'question',
            'session_answers' => 'answer',
            'attendance_device_requests' => 'device-request',
            'badges' => 'badge',
            default => 'proposed-change',
        };
        return (Str::slug(Str::limit((string) $label, 65, '')) ?: 'record').'-'.strtolower((string) Str::ulid());
    }
}
