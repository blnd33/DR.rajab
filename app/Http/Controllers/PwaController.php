<?php

namespace App\Http\Controllers;

use App\Services\Settings\SettingsRepository;
use App\Support\Locale\LocaleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PwaController extends Controller
{
    /**
     * Localised web app manifest (name, language and direction follow the user).
     */
    public function manifest(SettingsRepository $settings, LocaleRegistry $locales): JsonResponse
    {
        $name = (string) $settings->get('company.name', config('app.name'));

        return response()->json([
            'id' => '/app',
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => __('Orthopedic inventory and surgery logging'),
            'lang' => app()->getLocale(),
            'dir' => $locales->direction(),
            'start_url' => '/app?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#F8FAFC',
            'theme_color' => '#0E7490',
            'categories' => ['medical', 'business', 'productivity'],
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => __('Scan'), 'url' => '/app/scan', 'icons' => [['src' => asset('icons/icon-192.png'), 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * The Workbox service worker is built into public/build; served from the root
     * so its scope can cover both /app and /admin.
     */
    public function serviceWorker(): Response
    {
        $path = public_path('build/sw.js');

        abort_unless(is_file($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
