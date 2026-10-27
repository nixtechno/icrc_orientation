<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfService
{
    public function render(string $html, ?array $paper = null): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [public_path('brand'), storage_path('app/private')]);
        $cache = storage_path('app/private/pdf-cache');
        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }$options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper($paper ?? 'a4');
        $pdf->render();

        return $pdf->output();
    }

    public static function image(?string $path): ?string
    {
        if (! $path || ! is_file($path)) {
            return null;
        }$mime = mime_content_type($path);
        if (! in_array($mime, ['image/png', 'image/jpeg'])) {
            return null;
        }

return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }
}
