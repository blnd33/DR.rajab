/**
 * Some scanners prefix an AIM symbology identifier, e.g. "]d2" (GS1 DataMatrix),
 * "]C1" (GS1-128), "]Q3" (GS1 QR), "]e0" (GS1 DataBar). Strip it but remember it,
 * because "]d2"/"]C1"/"]Q3" mean the payload is GS1-encoded.
 */
const AIM_PREFIX = /^\](?<id>[A-Za-z][0-9A-Za-z])/

export function stripSymbologyId(raw) {
    const match = raw.match(AIM_PREFIX)
    if (!match) return { value: raw.trim(), symbology: null }

    return { value: raw.slice(match[0].length).trim(), symbology: match.groups.id }
}

export function isGs1Symbology(id) {
    return ['d2', 'C1', 'Q3', 'e0'].includes(id)
}
