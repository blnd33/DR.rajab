/**
 * Offline banner + "N items waiting to sync" (drafts saved in IndexedDB).
 */
import { pendingCount } from '../offline/db'

export default () => ({
    online: navigator.onLine,
    pending: 0,

    init() {
        window.addEventListener('online', () => {
            this.online = true
            window.dispatchEvent(new CustomEvent('ot:back-online'))
        })
        window.addEventListener('offline', () => (this.online = false))
        window.addEventListener('ot:offline-store-changed', () => this.refresh())

        this.refresh()
    },

    async refresh() {
        try {
            this.pending = await pendingCount()
        } catch {
            this.pending = 0 // IndexedDB unavailable (private mode) — drafts disabled.
        }
    },

    get visible() {
        return !this.online || this.pending > 0
    },
})
