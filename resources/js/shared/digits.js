/**
 * Arabic-Indic (U+0660–0669) and Persian (U+06F0–06F9) digits → Western digits.
 * Mirrors App\Support\Locale\DigitConverter::toWestern().
 */
const ARABIC_INDIC = /[٠-٩]/g
const PERSIAN = /[۰-۹]/g

export function toWestern(value) {
    if (typeof value !== 'string' || value === '') return value

    return value
        .replace(ARABIC_INDIC, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(PERSIAN, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/٫/g, '.') // Arabic decimal separator
        .replace(/٬/g, '') // Arabic thousands separator
}

/**
 * Numeric-looking inputs convert digits while typing, so validation never sees ١٢٣.
 * Free-text inputs are normalised on the server instead (no surprise while typing).
 */
const NUMERIC_SELECTOR = [
    'input[type="number"]',
    'input[type="tel"]',
    'input[inputmode="numeric"]',
    'input[inputmode="decimal"]',
    'input[data-western-digits]',
].join(',')

export function installDigitNormalizer(root = document) {
    root.addEventListener(
        'input',
        (event) => {
            const input = event.target
            if (!(input instanceof HTMLInputElement) || !input.matches(NUMERIC_SELECTOR)) return

            const normalized = toWestern(input.value)
            if (normalized === input.value) return

            // type=number inputs do not expose selection; others keep the caret in place.
            const caret = input.type === 'number' ? null : input.selectionStart
            input.value = normalized
            if (caret !== null) input.setSelectionRange(caret, caret)
            input.dispatchEvent(new Event('input', { bubbles: true }))
        },
        true,
    )
}
