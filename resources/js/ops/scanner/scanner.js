/**
 * Scanner — camera (ZXing) + hardware scanners that type like a keyboard (USB/Bluetooth "wedge").
 *
 * Usage (Blade):
 *   <div x-data="scanner({ method: 'handleScan' })"> … </div>
 * Every scan calls $wire[method](raw) when `method` is set and dispatches a `scanned`
 * browser event { raw, symbology, source }. Works client-side; parsing (GS1 etc.)
 * happens on the server (Phase 1 parsers).
 */
import { feedback } from './feedback'
import { stripSymbologyId } from './symbology'

const WEDGE_MAX_GAP_MS = 35 // scanners type much faster than people
const WEDGE_MIN_LENGTH = 4
const DUPLICATE_WINDOW_MS = 1500

export default (options = {}) => ({
    method: options.method ?? null,
    cameraOn: false,
    cameraError: null,
    starting: false,
    manual: '',
    lastScan: null,

    _controls: null,
    _buffer: '',
    _lastKeyAt: 0,
    _onKeydown: null,

    init() {
        this._onKeydown = (event) => this.onKeydown(event)
        window.addEventListener('keydown', this._onKeydown, true)
    },

    destroy() {
        window.removeEventListener('keydown', this._onKeydown, true)
        this.stopCamera()
    },

    async startCamera() {
        if (this.cameraOn || this.starting) return
        this.starting = true
        this.cameraError = null

        try {
            // Loaded on demand: keeps the first page light on low-end phones.
            const [{ BrowserMultiFormatReader }, { DecodeHintType, BarcodeFormat }] = await Promise.all([
                import('@zxing/browser'),
                import('@zxing/library'),
            ])

            const hints = new Map()
            hints.set(DecodeHintType.POSSIBLE_FORMATS, [
                BarcodeFormat.DATA_MATRIX,
                BarcodeFormat.QR_CODE,
                BarcodeFormat.CODE_128,
                BarcodeFormat.EAN_13,
                BarcodeFormat.EAN_8,
                BarcodeFormat.UPC_A,
                BarcodeFormat.CODE_39,
            ])
            hints.set(DecodeHintType.TRY_HARDER, true)

            const reader = new BrowserMultiFormatReader(hints, { delayBetweenScanAttempts: 120 })

            this._controls = await reader.decodeFromConstraints(
                { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } },
                this.$refs.video,
                (result) => {
                    if (result) this.emit(result.getText(), 'camera', result.getBarcodeFormat?.())
                },
            )

            this.cameraOn = true
        } catch (error) {
            this.cameraError = error?.name === 'NotAllowedError' ? 'denied' : 'unavailable'
        } finally {
            this.starting = false
        }
    },

    stopCamera() {
        this._controls?.stop()
        this._controls = null
        this.cameraOn = false
    },

    toggleCamera() {
        this.cameraOn ? this.stopCamera() : this.startCamera()
    },

    submitManual() {
        const value = this.manual.trim()
        if (value === '') return
        this.emit(value, 'manual')
        this.manual = ''
    },

    onKeydown(event) {
        // Typing into a normal field (not the scan field) is left alone unless it is clearly a scanner burst.
        const now = performance.now()
        const gap = now - this._lastKeyAt
        this._lastKeyAt = now

        if (gap > WEDGE_MAX_GAP_MS * 2) this._buffer = ''

        if (event.key === 'Enter') {
            if (this._buffer.length >= WEDGE_MIN_LENGTH && gap <= WEDGE_MAX_GAP_MS * 2) {
                const target = event.target
                const inScanField = target?.dataset?.scanField !== undefined
                if (!inScanField) event.preventDefault()
                this.emit(this._buffer, 'wedge')
                if (inScanField) this.manual = ''
            }
            this._buffer = ''
            return
        }

        if (event.key.length === 1) {
            this._buffer += event.key
        } else if (event.key === 'Unidentified' || event.keyCode === 29) {
            this._buffer += '\x1D' // GS / FNC1 separator from GS1 scanners
        }
    },

    emit(raw, source, format = null) {
        const { value, symbology } = stripSymbologyId(String(raw))
        if (value === '') return

        const now = Date.now()
        if (this.lastScan && this.lastScan.value === value && now - this.lastScan.at < DUPLICATE_WINDOW_MS) return
        this.lastScan = { value, at: now }

        feedback('success')

        const detail = { raw: value, symbology: symbology ?? format, source }
        this.$dispatch('scanned', detail)

        if (this.method && this.$wire?.[this.method]) {
            this.$wire[this.method](value, source)
        }
    },
})
