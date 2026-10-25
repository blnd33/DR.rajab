/**
 * IndexedDB store for the offline-tolerant pieces (brief §2.3):
 *  - drafts:     surgery drafts (Phase 3), keyed by client_uuid
 *  - scanQueues: transfer-receive / stock-count scan lists (Phase 2), keyed by client_uuid
 *
 * Business data is never edited offline except these drafts. Each record carries
 * a client_uuid so the sync endpoint is idempotent (a retry never applies twice).
 */
import { openDB } from 'idb'

const DB_NAME = 'orthotrack'
const DB_VERSION = 1

let dbPromise = null

function db() {
    dbPromise ??= openDB(DB_NAME, DB_VERSION, {
        upgrade(database) {
            if (!database.objectStoreNames.contains('drafts')) {
                const drafts = database.createObjectStore('drafts', { keyPath: 'client_uuid' })
                drafts.createIndex('by_status', 'status')
            }

            if (!database.objectStoreNames.contains('scanQueues')) {
                const queues = database.createObjectStore('scanQueues', { keyPath: 'client_uuid' })
                queues.createIndex('by_status', 'status')
            }
        },
    })

    return dbPromise
}

export function newClientUuid() {
    return crypto.randomUUID()
}

export async function put(store, record) {
    const value = { status: 'pending', updated_at: new Date().toISOString(), ...record }
    await (await db()).put(store, value)
    notifyChange()
    return value
}

export async function get(store, clientUuid) {
    return (await db()).get(store, clientUuid)
}

export async function remove(store, clientUuid) {
    await (await db()).delete(store, clientUuid)
    notifyChange()
}

export async function pending(store) {
    return (await db()).getAllFromIndex(store, 'by_status', 'pending')
}

export async function pendingCount() {
    const database = await db()
    const [drafts, queues] = await Promise.all([
        database.countFromIndex('drafts', 'by_status', 'pending'),
        database.countFromIndex('scanQueues', 'by_status', 'pending'),
    ])

    return drafts + queues
}

function notifyChange() {
    window.dispatchEvent(new CustomEvent('ot:offline-store-changed'))
}
