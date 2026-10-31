<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BadgeService
{
    public function snapshot($person, string $type): array
    {
        $profile = $type === 'u' ? ($person->profile ?? []) : [];
        $parts = preg_split('/\s+/', trim($person->name), 2);
        return [
            'name' => $person->name,
            'first_name' => $profile['first_name'] ?? $parts[0],
            'last_name' => $profile['surname'] ?? ($parts[1] ?? ''),
            'department' => $person->department,
            'designation' => $type === 'u' ? $person->designation : $person->job_title,
            'organisation' => ($type === 'u' ? ($profile['organisation'] ?? null) : $person->organisation) ?: 'Infrastructure Concession Regulatory Commission',
            'photo_path' => $person->photo_path,
            'category' => $type === 'u' ? $person->role : 'facilitator',
            'programme' => 'ICRC SCODEX 2026',
        ];
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
        $items = $badges->map(function ($badge) use ($writer, $settings, $sizes, $metrics, $font) {
            $snapshot = $badge->snapshot;
            $parts = preg_split('/\s+/', trim($snapshot['name']), 2);
            $snapshot += ['first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'designation' => '', 'organisation' => $snapshot['department'] ?? 'ICRC'];
            $verifyUrl = url('/badges/'.$badge->getRouteKey().'/verify');
            $qr = 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($verifyUrl));
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
