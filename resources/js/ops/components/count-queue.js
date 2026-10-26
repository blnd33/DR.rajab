/**
 * Stock count scans, offline-tolerant (brief §2.3, P2-6).
 *
 * Every scan is first saved in IndexedDB (scanQueues) with a client_uuid, then sent to
 * /app/sync/count-scans. If the phone is offline the scans wait and are sent when the
 * connection is back ("ot:back-online"). The server ignores replays of the same uuid, so a
 * retry never counts twice. Usage:
 *   <div x-data="countQueue({ countId: 12, endpoint: '/app/sync/count-scans' })"
 *        @scanned.window="onScan($event.detail.raw)">
 */
import { feedback } from '../scanner/feedback'
import { newClientUuid, pending, put, remove } from '../offline/db'

export default (options = {}) => ({
    countId: options.countId,
    endpoint: options.endpoint,
    queued: 0,
    syncing: false,
    lastMessage: null,

    init() {
        window.addEventListener('ot:back-online', () => this.flush())
        this.flush()
    },

    async onScan(raw) {
        const record = {
            client_uuid: newClientUuid(),
            count_id: this.countId,
            raw,
            quantity: 1,
            scanned_at: new Date().toISOString(),
        }

        try {
            await put('scanQueues', record)
        } catch {
            // IndexedDB unavailable (private mode): send straight away.
            await this.send([record])
            return
        }

        await this.flush()
    },

    async flush() {
        let items = []

        try {
            items = (await pending('scanQueues')).filter((r) => r.count_id === this.countId)
        } catch {
            items = []
        }

        this.queued = items.length

        if (this.syncing || items.length === 0 || !navigator.onLine) return

        await this.send(items, true)
    },

    async send(items, fromQueue = false) {
        this.syncing = true

        try {
            const response = await fetch(this.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ scans: items.map(({ status, updated_at, ...scan }) => scan) }),
            })

            if (!response.ok) throw new Error(String(response.status))

            const body = await response.json()

            if (fromQueue) {
                for (const uuid of body.accepted ?? []) await remove('scanQueues', uuid)
            }

            const last = (body.results ?? []).at(-1)
            if (last) {
                feedback(last.ok ? 'success' : 'error')
                this.lastMessage = last.message
                if (last.message) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: last.ok ? 'info' : 'warning', message: last.message } }))
                }
            }

            this.$wire.$refresh()
        } catch {
            // Offline or server busy: the scans stay queued and are retried later.
            feedback('error')
        } finally {
            this.syncing = false
            try {
                this.queued = (await pending('scanQueues')).filter((r) => r.count_id === this.countId).length
            } catch {
                this.queued = 0
            }
        }
    },
})
