<?php

namespace App\Services;

use App\Models\{Badge, Facilitator, User};
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BadgeService
{
    /** A QR code as PNG bytes, for email bodies and attachments where SVG is not displayed. */
    public static function qrPng(string $text, int $size = 480): string
    {
        return (new Writer(new \BaconQrCode\Renderer\GDLibRenderer($size, 4)))->writeString($text);
    }

    public function snapshot($person, string $type): array
    {
        $profile = $type === 'u' ? ($person->profile ?? []) : [];
        $parts = preg_split('/\s+/', trim($person->name), 2);
        return [
            'name' => $person->name,
            'first_name' => $profile['first_name'] ?? $parts[0],
            'last_name' => $profile['surname'] ?? ($parts[1] ?? ''),
            'department' => $person->department,
            'staff_number' => $type === 'u' ? $person->staff_number : null,
            'designation' => $type === 'u' ? $person->designation : $person->job_title,
            'organisation' => ($type === 'u' ? ($profile['organisation'] ?? null) : $person->organisation) ?: 'Infrastructure Concession Regulatory Commission',
            'photo_path' => $person->photo_path,
            'category' => $type === 'u' ? $person->role : 'facilitator',
            'programme' => 'ICRC SCODEX 2026',
        ];
    }

    /**
     * Brings a person's badges in line with their current details after a profile edit.
     * A badge whose details change is a new version that must be printed and collected again.
     */
    public function refresh(User|Facilitator $person): void
    {
        $type = $person instanceof User ? 'u' : 'f';
        foreach (Badge::where('subject_key', $type.':'.$person->id)->get() as $badge) {
            $snapshot = $this->snapshot($person, $type);
            // Keep a facilitator's name split from the badge editor while the name itself is unchanged.
            if ($type === 'f' && ($badge->snapshot['name'] ?? null) === $snapshot['name']) {
                $snapshot['first_name'] = $badge->snapshot['first_name'] ?? $snapshot['first_name'];
                $snapshot['last_name'] = $badge->snapshot['last_name'] ?? $snapshot['last_name'];
            }
            if ($badge->snapshot === $snapshot) continue;
            $before = $badge->toArray();
            $badge->fill(['snapshot' => $snapshot, 'category' => $snapshot['category'], 'version' => $badge->version + 1, 'printed_at' => null, 'collected_at' => null, 'generated_at' => now(), 'generated_by' => auth()->id() ?? $badge->generated_by])->save();
            Access::audit('badge.profile_updated', (string) $badge->id, $before, $badge->toArray());
        }
    }

    /**
     * Badges whose print-ready PDF was opened but were never recorded as printed, as badges made with Create badge were:
     * each is marked printed and collected when its PDF was first opened after its current version was generated.
     */
    public function markPrintedFromPdfs(bool $save = true): int
    {
        $badges = Badge::where('programme_id', Access::programme()->id)->whereNull('printed_at')->get();
        $opened = \Illuminate\Support\Facades\DB::table('audit_logs')->where('action', 'badge.pdf')->orderBy('created_at')->get(['subject', 'created_at'])
            ->flatMap(fn ($log) => array_map(fn ($id) => ['id' => (int) $id, 'at' => \Illuminate\Support\Carbon::parse($log->created_at)], explode(',', $log->subject)))->groupBy('id');
        $printed = $badges->mapWithKeys(fn ($badge) => [$badge->id => $opened->get($badge->id, collect())->first(fn ($o) => ! $badge->generated_at || $o['at']->gte($badge->generated_at))['at'] ?? null])->filter();
        if ($save && $printed->isNotEmpty()) {
            $badges->only($printed->keys()->all())->each(fn ($badge) => $badge->update(['printed_at' => $printed[$badge->id], 'collected_at' => $badge->collected_at ?? $printed[$badge->id]]));
            Access::audit('badge.printed_from_pdf', 'programme:'.Access::programme()->id, null, ['badges' => $printed->count()]);
        }

        return $printed->count();
    }

    /** Badges handed out as they were printed: each printed badge is marked collected at its print time. */
    public function markPrintedCollected(bool $save = true): int
    {
        $badges = Badge::where('programme_id', Access::programme()->id)->whereNotNull('printed_at')->whereNull('collected_at')->get();
        if ($save && $badges->isNotEmpty()) {
            $badges->each(fn ($badge) => $badge->update(['collected_at' => $badge->printed_at]));
            Access::audit('badge.collected_when_printed', 'programme:'.Access::programme()->id, null, ['badges' => $badges->count()]);
        }

        return $badges->count();
    }

    public function data($badges, ?Request $request = null): array
    {
        $p = Access::programme();
        $settings = array_replace(['width_mm' => 97, 'height_mm' => 138, 'layout' => 'folded', 'label' => 'ICRC SCODEX 2026', 'footer' => 'Empowering People. Building Capacity. Driving Excellence.', 'accent' => '#075332'], $p->settings['badge'] ?? []);
        $fontScale = min($settings['width_mm'] / 97, $settings['height_mm'] / 138);
        $sizes = ['name' => 8.5, 'title' => 3.4, 'organisation' => 3.4];
        if ($request) {
            $validated = $request->validate(['name_size' => 'nullable|numeric|between:4,12', 'title_size' => 'nullable|numeric|between:2,6', 'organisation_size' => 'nullable|numeric|between:2,6', 'layout' => 'nullable|in:folded,single']);
            foreach ($sizes as $key => $value) if (isset($validated[$key.'_size'])) $sizes[$key] = (float) $validated[$key.'_size'];
            if (isset($validated['layout'])) $settings['layout'] = $validated['layout'];
        }
        $logo = PdfService::image(public_path('brand/ICRC_Logo.png'));
        $producer = PdfService::image(public_path('brand/BREVITY_logo.png'));
        $background = 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(public_path('brand/badge-contours.svg')));
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 4), new SvgImageBackEnd));
        $metrics = (new \Dompdf\Dompdf)->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans', 'bold');
        $people = User::whereIn('id', $badges->pluck('user_id')->filter())->get()->keyBy('id');
        $items = $badges->map(function ($badge) use ($writer, $settings, $sizes, $metrics, $font, $people) {
            $snapshot = $badge->snapshot;
            $parts = preg_split('/\s+/', trim($snapshot['name']), 2);
            $snapshot += ['first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'designation' => '', 'organisation' => $snapshot['department'] ?? 'ICRC'];
            $verifyUrl = url('/badges/'.$badge->getRouteKey().'/verify');
            // Account badges carry the person's numeric QR ID for attendance scanners; facilitator-only badges keep the verification link.
            $person = $badge->user_id ? $people->get($badge->user_id) : null;
            $qr = 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($person ? $person->qrCode() : $verifyUrl));
            // Badges made before staff numbers were captured use the account's current number.
            $snapshot['staff_number'] = ($snapshot['staff_number'] ?? null) ?: $person?->staff_number;
            $photo = ($snapshot['photo_path'] ?? null) ? PdfService::image(Storage::disk('local')->path($snapshot['photo_path'])) : null;
            $colour = match ($badge->category) { 'admin' => '#8b6726', 'facilitator' => '#24536b', default => $settings['accent'] };
            $fit = $sizes;
            $nameWidth = max($metrics->getTextWidth(mb_strtoupper($snapshot['first_name']), $font, 1), $metrics->getTextWidth(mb_strtoupper($snapshot['last_name']), $font, 1));
            $fit['name'] = min($sizes['name'], max(3, 79.54 / max(1, $nameWidth) * .94));
            foreach (['title' => 'designation', 'organisation' => 'organisation'] as $key => $field) {
                $width = $metrics->getTextWidth(mb_strtoupper($snapshot[$field] ?? ''), $font, 1);
                while ($fit[$key] > 1.4 && ceil($width * $fit[$key] / 72) * $fit[$key] * 1.35 > 9) $fit[$key] -= .1;
            }
            return compact('badge', 'snapshot', 'qr', 'photo', 'colour', 'fit', 'verifyUrl');
        });
        return compact('items', 'settings', 'sizes', 'fontScale', 'logo', 'producer', 'background', 'p');
    }
}
