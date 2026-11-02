<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\ProgrammeUpdate;
use Illuminate\Support\Facades\DB;

/** Emails assigned staff once about a published assessment, assignment or presentation. */
class ActivityEmails
{
    /** Emails everyone assigned who has not been told yet; does nothing while the activity is a draft. */
    public function notifyAssigned(Activity $activity): int
    {
        if (! $activity->published) {
            return 0;
        }
        $pending = DB::table('activity_assignments')->where('activity_id', $activity->id)->whereNull('notified_at')->pluck('user_id');
        $people = User::whereIn('id', $pending)->where('active', true)->get();
        if ($people->isEmpty()) {
            return 0;
        }
        $notification = new ProgrammeUpdate(...$this->message($activity));
        foreach ($people as $person) {
            $person->notify($notification);
        }
        DB::table('activity_assignments')->where('activity_id', $activity->id)->whereIn('user_id', $people->modelKeys())->update(['notified_at' => now()]);

        return $people->count();
    }

    /**
     * Keeps the activity's announcement in step with publication: published work gets one, seen only by
     * the staff assigned to it, and unpublishing archives it. Does nothing before that migration has run.
     */
    public function announce(Activity $activity): void
    {
        if (! Announcement::linksActivities()) {
            return;
        }
        $announcement = Announcement::where('activity_id', $activity->id)->first();
        if (! $activity->published) {
            $announcement?->update(['archived' => true]);

            return;
        }
        [$title, $body] = $this->message($activity);
        $announcement ??= new Announcement(['activity_id' => $activity->id, 'programme_id' => $activity->programme_id, 'published_at' => now()]);
        $announcement->fill(['title' => $title, 'body' => $body, 'archived' => false])->save();
    }

    /** @return array{string, string, string, string} subject, body, link and button text */
    private function message(Activity $activity): array
    {
        $p = Access::programme();
        $when = fn ($date) => $date->timezone($p->timezone)->format('l j F Y, H:i');
        $label = $activity->label();
        $lines = ['A new '.$label.' has been assigned to you: "'.$activity->title.'".'];
        if ($activity->available_at && $activity->available_at->isFuture()) {
            $lines[] = 'It opens '.$when($activity->available_at).'.';
        }
        if ($activity->due_at) {
            $lines[] = 'It is due '.$when($activity->due_at).' ('.$p->timezoneLabel().' time).';
        }

        return ['New '.$label.': '.$activity->title, implode("\n\n", $lines), url('/activities/'.$activity->getRouteKey()), 'Open the '.$label];
    }
}
