/**
 * Registers the Workbox service worker (built to public/build/sw.js, served at /sw.js).
 * Skipped in Vite dev mode, where no service worker is built.
 */
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return

    const url = document.querySelector('meta[name="ot-sw"]')?.content
    if (!url || import.meta.env.DEV) return

    window.addEventListener('load', () => {
        navigator.serviceWorker.register(url, { scope: '/' }).catch((error) => {
            console.warn('Service worker registration failed', error)
        })
    })
}
