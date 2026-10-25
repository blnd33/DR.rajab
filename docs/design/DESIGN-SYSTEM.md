# OrthoTrack design system

Derived with the `/ui-ux-pro-max` skill (query: data-dense healthcare admin tool → **Minimalism / Swiss style**, accessibility risk: low; palette: *Medical Clinic* clinical teal, adjusted for WCAG AA). The skill's first suggestion (Neumorphism landing page, accessibility risk: high) was rejected for an operational tool.

Tokens live in [`resources/css/tokens.css`](../../resources/css/tokens.css) and are shared by the Ops app and the Filament theme.

## Principles

1. **Obvious over clever.** Non-technical staff, gloves, bright OR lights, low-end Android phones. One primary action per screen.
2. **Scan first.** Wherever a product, serial, kit or instrument is chosen, a scan works (camera or hardware scanner).
3. **Never color alone.** Every status = icon + text + color.
4. **RTL-native.** Arabic and Sorani are first-class, not mirrored afterthoughts.
5. **Calm.** Flat surfaces, one accent color, minimal motion.

## Color

| Token | Hex | Use | Contrast |
|---|---|---|---|
| `primary` | `#0E7490` | primary buttons, links, active nav | white text 5.4:1 |
| `primary-strong` | `#155E75` | hover/pressed | 7.4:1 |
| `primary-soft` | `#ECFEFF` | selected backgrounds, role chip | — |
| `bg` | `#F8FAFC` | page | — |
| `card` | `#FFFFFF` | cards, bars | — |
| `fg` | `#0F172A` | text | 17.9:1 on card |
| `muted-fg` | `#475569` | secondary text | 7.6:1 |
| `border` / `border-strong` | `#E2E8F0` / `#CBD5E1` | dividers / inputs | — |
| `success` | `#15803D` | done, active | 5.0:1 |
| `warning` | `#B45309` | attention | 5.0:1 |
| `danger` | `#B91C1C` | errors, destructive | 6.5:1 |
| `info` | `#1D4ED8` | info, sync | 6.7:1 |

**Expiry levels** (Module 6) use the colors the Super Admin picks. The chip text color (black or white) is computed from luminance, so a yellow "Attention" chip stays readable. The label and the days left are always shown next to the color.

Filament uses the same primary (`Color::hex('#0E7490')`) with slate grays; Filament picks accessible text colors automatically.

## Typography

| Script | Font | Notes |
|---|---|---|
| Latin (en) | Inter Variable | 16px base, line-height 1.5 |
| Arabic / Sorani | Vazirmatn Variable | 17px base, line-height 1.6 — covers ڕ ڵ ێ ۆ ە ڤ گ چ پ ژ |
| Codes | JetBrains Mono Variable | serial, lot, SKU, barcode, document no. — clear 0/O, 1/l |

All fonts are self-hosted (fontsource → Vite → precached by the service worker). Nothing loads from a CDN, so they also work offline and behind slow connections.

**Codes:** always `<x-code>` (Blade) or `CodeColumn` (Filament) → `dir="ltr"`, monospace, Western digits, `unicode-bidi: isolate` so they never flip inside Arabic text.

**Numbers & dates:** `<x-num>`, `<x-date>`, `Display::number()/date()/dateTime()`. Dates are always `YYYY-MM-DD`. Digits follow Settings → Languages & numbers.

## Layout (Ops app)

* Mobile-first, max content width `screen-md` (768px), 16px side padding.
* Sticky top bar (logo, branch switcher, notifications bell) + fixed bottom nav (**max 5 items**; Scan is the raised center action).
* Safe areas (`env(safe-area-inset-*)`) respected for installed PWAs.
* Lists become cards on small screens; tables only in the admin panel.

## Touch & interaction

* Minimum target **48×48px** (`--spacing-touch`); 8px+ between targets.
* Inputs ≥16px font (stops iOS zoom), `min-height: 48px`.
* Buttons: `.ot-btn` + `.ot-btn-primary | -secondary | -danger`; pressed state scales to 0.98 (100ms).
* Scan feedback: short high beep + 40ms vibration for success, low double-buzz for errors. You can scan without looking at the screen.
* Irreversible actions: confirmation sheet/dialog with a required reason where the brief demands one.
* Loading: `wire:loading` disables buttons; skeletons for lists (no layout shift).
* Toasts: bottom, icon + text, announced via `aria-live`; errors stay twice as long.
* `prefers-reduced-motion`: transitions/animations off.

## RTL rules

* Only logical utilities: `ms-/me-/ps-/pe-/start-/end-/border-s/rounded-s…`. Never `ml-/mr-/pl-/pr-/left-/right-`.
* Directional icons (arrows, chevrons) flip with `rtl:` variants; brand marks and media controls do not.
* Mixed content (Arabic text + serial number) relies on `<bdi>`/`unicode-bidi: isolate`.

## Icons

Heroicons (outline, 24px) via `@svg('heroicon-o-…')` in the Ops app and `Heroicon::Outlined…` in Filament. No emoji as icons. Icon-only buttons always have `aria-label`.

## Accessibility checklist (per screen)

- [ ] Text contrast ≥ 4.5:1, UI elements ≥ 3:1
- [ ] Visible focus ring (`:focus-visible`, 2px primary)
- [ ] Every input has a visible label (no placeholder-only labels)
- [ ] Errors next to the field, in the user's language
- [ ] Status chips include text
- [ ] Works at 360px width, 200% zoom, RTL
- [ ] Touch targets ≥ 48px
