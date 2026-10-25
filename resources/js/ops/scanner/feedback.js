/**
 * Scan feedback: short beep + vibration. Distinct patterns for success and error,
 * so staff can scan without looking at the screen.
 */
let audioContext = null

function beep(frequency, durationMs) {
    try {
        audioContext ??= new (window.AudioContext || window.webkitAudioContext)()
        const oscillator = audioContext.createOscillator()
        const gain = audioContext.createGain()
        oscillator.type = 'square'
        oscillator.frequency.value = frequency
        gain.gain.value = 0.05
        oscillator.connect(gain).connect(audioContext.destination)
        oscillator.start()
        oscillator.stop(audioContext.currentTime + durationMs / 1000)
    } catch {
        // Audio not available — vibration only.
    }
}

export function feedback(type = 'success') {
    if (type === 'error') {
        beep(220, 250)
        navigator.vibrate?.([80, 60, 80])
        return
    }

    beep(1760, 80)
    navigator.vibrate?.(40)
}

// Livewire components can trigger: $this->dispatch('scan-feedback', type: 'error')
window.addEventListener('scan-feedback', (event) => feedback(event.detail?.type))
