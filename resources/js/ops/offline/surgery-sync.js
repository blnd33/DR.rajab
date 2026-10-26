/**
 * Surgery drafts saved on the phone (brief §2.3, P3-4).
 *
 * The surgery form keeps its draft in IndexedDB ("drafts", status "editing"). When the doctor
 * saves or completes it, the draft becomes "pending" and is sent to /app/sync/surgeries. If the
 * phone is offline it waits, and is sent from any page when the connection is back. The server
 * updates the same draft for the same client_uuid and ignores replays of a completed surgery.
 */
import { pending, remove } from './db'

const ENDPOINT = '/app/sync/surgeries'

let flushing = null

/**
 * Send every pending draft. Returns the server results ([] when offline or on error).
 */
export function flushSurgeryDrafts() {
    flushing ??= send().finally(() => {
        flushing = null
    })

    return flushing
}

async function send() {
    if (!navigator.onLine) return []

    let drafts = []

    try {
        drafts = await pending('drafts')
    } catch {
        return []
    }

    if (drafts.length === 0) return []

    try {
        const response = await fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ drafts: drafts.map((d) => d.payload) }),
        })

        if (!response.ok) return []

        const body = await response.json()

        for (const uuid of body.accepted ?? []) {
            await remove('drafts', uuid)
        }

        window.dispatchEvent(new CustomEvent('ot:surgeries-synced', { detail: body.results ?? [] }))

        return body.results ?? []
    } catch {
        // Offline or server busy: the drafts stay on the phone and are retried later.
        return []
    }
}

export function installSurgerySync() {
    window.addEventListener('ot:back-online', () => flushSurgeryDrafts())
    flushSurgeryDrafts()
}
