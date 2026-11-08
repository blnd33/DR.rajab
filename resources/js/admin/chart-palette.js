// Theme-aware categorical colours for the admin charts (dataviz method, validated with
// validate_palette.js: light steps on #ffffff, dark steps on #18181b — all checks pass).
// Datasets opt in: `otSlots` (one slot per data point, -1 = "Other") or `otSlot` (one slot).
// Slots are assigned in fixed order and never cycled past eight.

const LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948']
const DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767']
const OTHER = { light: '#a1a1aa', dark: '#71717a' }
const SURFACE = { light: '#ffffff', dark: '#18181b' }

const isDark = () => document.documentElement.classList.contains('dark')

const paletteSlot = (palette, slot) => palette[Math.min(Math.max(slot, 0), palette.length - 1)]

const otPalette = {
    id: 'otPalette',
    beforeUpdate(chart) {
        const dark = isDark()
        const palette = dark ? DARK : LIGHT

        for (const dataset of chart.data?.datasets ?? []) {
            if (Array.isArray(dataset.otSlots)) {
                const colours = dataset.otSlots.map((slot) => (slot < 0 ? (dark ? OTHER.dark : OTHER.light) : paletteSlot(palette, slot)))
                dataset.backgroundColor = colours
                dataset.hoverBackgroundColor = colours
                dataset.borderColor = dark ? SURFACE.dark : SURFACE.light
                dataset.hoverBorderColor = dataset.borderColor
                dataset.borderWidth = 2
            } else if (Number.isInteger(dataset.otSlot)) {
                const colour = paletteSlot(palette, dataset.otSlot)
                dataset.backgroundColor = colour
                dataset.hoverBackgroundColor = colour
                dataset.borderColor = colour
                dataset.hoverBorderColor = colour
                dataset.pointBackgroundColor = colour
            }
        }
    },
}

export function installChartPalette() {
    window.filamentChartJsPlugins = [...(window.filamentChartJsPlugins ?? []), otPalette]
}
