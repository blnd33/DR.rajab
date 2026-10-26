/**
 * Phone surgery form (Module 10, P3-4): every change is kept in IndexedDB so nothing is lost
 * when the connection drops. Save / Complete hands the draft to the sync queue.
 * Usage: <form x-data="surgeryForm({ initial, patientLookup, strings })" @scanned.window="onScan($event.detail.raw)">
 */
import { feedback } from '../scanner/feedback'
import { get, newClientUuid, put, remove } from '../offline/db'
import { flushSurgeryDrafts } from '../offline/surgery-sync'

const emptyPatient = () => ({ full_name: '', phone: '', age: null, gender: '' })

export default (options = {}) => ({
    form: {
        client_uuid: null,
        items: [],
        assistant_ids: [],
        new_patient: emptyPatient(),
        ...(options.initial ?? {}),
    },
    patientMode: options.initial?.patient_id ? 'pick' : 'new',
    patientLabel: options.initial?.patient_label ?? '',
    patientQuery: '',
    patientResults: [],
    searching: false,
    sending: false,
    message: null,
    problems: options.initial?.problems ?? [],
    online: navigator.onLine,

    async init() {
        const resume = new URLSearchParams(window.location.search).get('draft')

        if (resume) {
            try {
                const saved = await get('drafts', resume)

                if (saved?.payload) {
                    this.form = { ...this.form, ...saved.payload }
                    this.patientMode = saved.payload.patient_id ? 'pick' : 'new'
                    this.patientLabel = saved.payload.patient_label ?? ''
                }
            } catch {
                // no IndexedDB: start fresh
            }
        }

        this.form.client_uuid ??= newClientUuid()
        this.form.new_patient ??= emptyPatient()

        this.$watch('form', () => this.keep())
        window.addEventListener('online', () => (this.online = true))
        window.addEventListener('offline', () => (this.online = false))
    },

    /** Keep the draft on the phone while it is being edited (not sent yet). */
    async keep() {
        try {
            await put('drafts', {
                client_uuid: this.form.client_uuid,
                status: 'editing',
                title: this.title(),
                payload: this.payload(false),
            })
        } catch {
            // IndexedDB unavailable (private mode): the form still works online.
        }
    },

    title() {
        return [this.form.surgery_date, this.patientMode === 'new' ? this.form.new_patient?.full_name : this.patientLabel].filter(Boolean).join(' · ')
    },

    payload(complete) {
        const data = JSON.parse(JSON.stringify(this.form))
        data.complete = complete
        data.patient_label = this.patientLabel

        if (this.patientMode === 'new') {
            data.patient_id = null
        } else {
            data.new_patient = null
        }

        data.items = (data.items ?? []).filter((i) => String(i.raw ?? '').trim() !== '' || i.stock_batch_id || i.product_id)

        return data
    },

    onScan(raw) {
        const code = String(raw).trim()

        if (code === '') return

        const same = this.form.items.find((i) => i.raw === code)

        if (same) {
            // A lot box scanned again counts one more; a serial only once.
            same.quantity = (Number(same.quantity) || 1) + 1
        } else {
            this.form.items.push({ raw: code, quantity: 1 })
        }

        feedback('success')
    },

    removeItem(index) {
        this.form.items.splice(index, 1)
    },

    async searchPatients() {
        const q = this.patientQuery.trim()

        if (q.length < 2 || !navigator.onLine) {
            this.patientResults = []
            return
        }

        this.searching = true

        try {
            const response = await fetch(`${options.patientLookup}?q=${encodeURIComponent(q)}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
            this.patientResults = response.ok ? await response.json() : []
        } catch {
            this.patientResults = []
        } finally {
            this.searching = false
        }
    },

    pickPatient(patient) {
        this.form.patient_id = patient.id
        this.patientLabel = patient.label
        this.patientResults = []
        this.patientQuery = ''
    },

    async submit(complete) {
        this.sending = true
        this.message = null
        this.problems = []
        const uuid = this.form.client_uuid

        try {
            await put('drafts', { client_uuid: uuid, status: 'pending', title: this.title(), payload: this.payload(complete) })
        } catch {
            // no IndexedDB: send straight away below
        }

        if (!navigator.onLine) {
            this.sending = false
            this.message = options.strings.savedOffline
            return
        }

        const results = await flushSurgeryDrafts()
        const mine = results.find((r) => r.client_uuid === uuid)
        this.sending = false

        if (mine?.ok) {
            feedback(mine.status === 'completed' ? 'success' : 'error')
            window.location.assign(mine.url)
            return
        }

        if (mine) {
            this.problems = mine.problems ?? []
            await this.keep()
            return
        }

        this.message = options.strings.savedOffline
    },

    async discard() {
        try {
            await remove('drafts', this.form.client_uuid)
        } catch {
            // nothing stored
        }

        window.location.assign(options.listUrl)
    },
})
