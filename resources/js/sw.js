/* OrthoTrack service worker (Workbox, injectManifest).
 *
 * Caches the app shell and static assets only. Business data is never cached:
 * pages and Livewire requests always go to the network; when the network is
 * down, navigations fall back to /offline. Drafts live in IndexedDB, not here.
 */
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching'
import { registerRoute, setCatchHandler } from 'workbox-routing'
import { CacheFirst, NetworkOnly } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'

const OFFLINE_URL = '/offline'
const OFFLINE_CACHE = 'ot-offline-v1'

self.skipWaiting()
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

cleanupOutdatedCaches()
precacheAndRoute(self.__WB_MANIFEST)

// Offline fallback page + icons, cached at install.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(OFFLINE_CACHE).then((cache) =>
            cache.addAll([OFFLINE_URL, '/icons/favicon.svg', '/icons/icon-192.png']),
        ),
    )
})

// Pages and Livewire: network only (never serve stale business data).
registerRoute(({ request }) => request.mode === 'navigate', new NetworkOnly())

// Static files outside /build (icons, uploaded logo).
registerRoute(
    ({ url }) => url.origin === self.location.origin && url.pathname.startsWith('/icons/'),
    new CacheFirst({
        cacheName: 'ot-icons-v1',
        plugins: [new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 30 })],
    }),
)

setCatchHandler(async ({ request }) => {
    if (request.mode === 'navigate') {
        const cache = await caches.open(OFFLINE_CACHE)
        return (await cache.match(OFFLINE_URL)) ?? Response.error()
    }

    return Response.error()
})

// Web push (Module 25).
self.addEventListener('push', (event) => {
    const data = event.data?.json() ?? {}

    event.waitUntil(
        self.registration.showNotification(data.title ?? 'OrthoTrack', {
            body: data.body ?? '',
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            data: { url: data.url ?? '/app/notifications' },
            dir: data.dir ?? 'auto',
            lang: data.lang ?? '',
        }),
    )
})

self.addEventListener('notificationclick', (event) => {
    event.notification.close()
    const url = event.notification.data?.url ?? '/app'

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((client) => client.url.includes('/app'))
            if (existing) {
                existing.navigate(url)
                return existing.focus()
            }
            return self.clients.openWindow(url)
        }),
    )
})
