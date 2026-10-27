<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * PDF download of a print page (P5-3): the same HTML rendered by headless Chrome, so Arabic
 * and Sorani shaping and right-to-left come out exactly as on screen. Without Chrome on the
 * server the print page (browser "Save as PDF") is the fallback.
 */
class PdfRenderer
{
    /** @var list<string> */
    private const CHROME_CANDIDATES = [
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    ];

    public function available(): bool
    {
        return (bool) config('orthotrack.pdf.enabled', true)
            && $this->chromePath() !== null
            && is_dir(base_path('node_modules/puppeteer'));
    }

    public function chromePath(): ?string
    {
        $configured = config('orthotrack.pdf.chrome_path');

        if (filled($configured)) {
            return is_file((string) $configured) ? (string) $configured : null;
        }

        foreach (self::CHROME_CANDIDATES as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Render the HTML to a PDF file response, or null when Chrome could not do it.
     */
    public function download(string $html, string $filename, bool $landscape = false): ?Response
    {
        if (! $this->available()) {
            return null;
        }

        // Load the built CSS and fonts from disk: Chrome must not call back into this server.
        $build = str_replace('\\', '/', public_path('build'));
        $html = str_replace(rtrim((string) url('/'), '/').'/build/', 'file:///'.ltrim($build, '/').'/', $html);
        $file = storage_path('app/tmp/pdf-'.Str::uuid().'.html');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $html);

        try {
            $pdf = Browsershot::htmlFromFilePath($file)
                ->setChromePath((string) $this->chromePath())
                ->setNodeModulePath(base_path('node_modules'))
                ->noSandbox()
                ->format('A4')
                ->landscape($landscape)
                ->margins(14, 12, 14, 12)
                ->showBackground()
                ->timeout(60)
                ->pdf();
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            File::delete($file);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::ascii($filename).'"',
        ]);
    }
}
