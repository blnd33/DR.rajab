# Phase 6 — Morning summary and Quick answers — plan

Two additions that help the doctor and his staff see what is going on without opening ten screens:

1. **Morning summary** — every morning the system sends each chosen person a short report of yesterday and what needs attention today.
2. **Quick answers** — a chat-style screen with ready-made questions ("Which kits are overdue?", "Sales this month?") and a box to type or scan any code.

**No AI is used.** Both read the numbers straight from the database, so they are exact, cost nothing to run, and work on the current server. They cannot understand any free sentence; they answer the questions listed below. (A paid AI that understands typed sentences can be added later on top of the same building blocks — see §6.)

Everything follows the existing rules: each person sees only what their permissions allow and only their branches; texts in English, Arabic and Kurdish; dates `YYYY-MM-DD`; money in the display currency, converted with each row's own exchange-rate snapshot (as the reports already do).

---

## 1. One shared engine — "facts"

Both features use the same small set of **fact** functions in `app/Services/Insight/`. A fact answers one thing for one user, a branch list and a period, for example *sales by hospital*, *kits out / overdue*, *expiring items*. Each fact:

- checks its own permission and branch scope (money facts need the money / report permissions, profit needs `reports.profit`);
- reuses the queries that already exist (Reports, Graphs, StockQuery, LowStock, KitService, Issues, approvals) — no second copy of business rules;
- returns plain data (numbers, a short list, a link to the full screen), which the summary and the chat then show.

Adding a new question or summary line later = adding one fact class.

## 2. Morning summary

### What it contains

| Section | Shows | Needs permission |
|---|---|---|
| **Yesterday at a glance** | surgeries done (by hospital, doctor), kits sent and returned, transfers sent / received, stock received | the matching *view* permission |
| **Money** | sales and payments received yesterday; unpaid hospital balances (total + top 5); supplier invoices due this week; cash in each cash box | `sales.view_any`, money / `reports.view` |
| **Kits** | kits out now, overdue kits (how many days), items missing after a return | `kits.view_any` |
| **Expiry and stock** | items expiring in 30 / 60 / 90 days (counts + first few), products below minimum | stock view |
| **Needs attention** | open issues (unaccounted implants, …), approvals waiting, transfers waiting to be received, draft surgeries not finished | the matching permission |

Empty sections are left out. If nothing at all happened and nothing needs attention, it says so in one line.

### How it arrives

- **Phone / browser notification** (the existing push) with one short line, e.g. *"Yesterday: 3 surgeries · $12,000 sales · 2 kits overdue · 5 items expire this month"*. Tapping it opens the full summary.
- **In-app notification** (the bell) — always.
- **Email** (optional) — needs a real mailbox on the server (today the test site only writes mail to the log). Turned on per person once the mailbox is set.
- **Privacy on the lock screen:** the push line holds counts and totals only — never patient names.

### When and for whom

- New permission **`summary.receive`** — Super Admin and Accountant have it by default; it can be given to any role or person.
- Each person chooses in their profile: on / off, the **time** (default 07:30, business time Asia/Baghdad), which **sections**, which **branches** (within their scope), and whether to skip Fridays.
- A scheduler job runs every 15 minutes and sends each person's summary once their time has come (never twice a day). It uses the cron that already runs on the server.
- "Yesterday" = the previous calendar day in business time.

### Kept for later

Each sent summary is saved (`morning_summaries`: user, date, the numbers as they were that morning). The summary page has a date picker, so the doctor can open any past morning.

### Screens

- **Admin:** *Insight → Morning summary* — today's summary, date picker for past days, link to settings. A small *Today* card on the dashboard.
- **Phone app:** a summary card at the top of Home; tapping opens `/app/summary` (same content, phone layout).
- **Profile (both):** the summary settings above.
- **Settings (Super Admin):** the default time and the default sections for new people.

## 3. Quick answers

### The screen

Admin: *Insight → Quick answers*. Phone app: an **Ask** button on Home (`/app/ask`).

It looks like a chat: the person taps a question (or types / scans), the answer appears as a card — a number or two, a short list, and **Open the full list**, which goes to the existing report or screen already filtered. Each question offers small choices where they make sense: period (today / yesterday / this week / this month / custom) and branch (all mine / one branch).

### Questions at launch

| Group | Questions |
|---|---|
| **Today / yesterday** | What happened today? · What happened yesterday? (same content as the morning summary, for any day) |
| **Stock** | What expires in the next 30 / 60 / 90 days? · What is below minimum? · How many of *[item]* do we have, and where? · Where is serial *[…]*? |
| **Kits** | Which kits are out now? · Which kits are overdue? · What is missing from returned kits? · Is *[kit]* complete? |
| **Surgeries** | Surgeries this week / month — by hospital, by doctor · Draft surgeries not finished |
| **Money** | Sales for a period — by hospital · Unpaid hospital balances · Supplier invoices due · Cash in the cash boxes now · Expenses this month — by category · Profit for a period (only with `reports.profit`) |
| **Needs attention** | Open issues · Approvals waiting · Transfers waiting to be received |

A person only sees the questions their permissions allow.

### Type or scan anything

The box at the bottom accepts:

- **any code** — implant SKU, GTIN, serial, lot, kit, instrument, box barcode: answered with what it is, how many, where (branch, kit, hospital), expiry, and its recent history. This reuses the existing scanner (all barcode formats it already reads).
- **document numbers** — TRF-…, SUR-…, sales invoice numbers, …: a card with its status and a link.
- **a patient file number** (doctors and Super Admin only).
- **words** — simple keyword matching in English, Arabic and Kurdish ("expire", "انتهاء", "بەسەرچوون", "kit", "sales", …) suggests the closest questions. If nothing matches: *"I can answer these:"* and the list.

It does not understand full sentences — that is the paid AI option.

### Who can use it

New permission **`assistant.use`** — given by default to Super Admin, Accountant, Doctor, Cashier and Branch staff. What each person gets inside it is limited by their existing permissions and branches.

Nothing typed is stored; only the answer cards on screen until the page is closed.

## 4. Changes in the code (for the developer)

- `app/Services/Insight/` — `Fact` (interface), one class per fact, `FactRegistry` (config list, like reports / graphs), `MorningSummaryBuilder`, `MorningSummarySender`, `QuickQuestion` registry, `CodeLookup` (wraps ScanResolver + AssetLookup + document numbers + patients), `KeywordMatcher` (en / ar / ckb word lists in `lang/`).
- Migration: `morning_summaries` (user_id, summary_date, payload json, sent_at, unique user+date); per-user settings in the existing notification preferences / a `summary_settings` json column on `users`.
- Notification `MorningSummary` (extends `AppNotification`: database + web push; mail channel added when a mailbox exists).
- Command `summary:send` scheduled every 15 minutes (`withoutOverlapping`, heartbeat like the other daily jobs).
- Permissions `summary.receive`, `assistant.use` in `PermissionRegistry` + role defaults + `PermissionSeeder`.
- Screens: Filament pages `MorningSummaryPage`, `QuickAnswersPage` (navigation group *Insight*), dashboard card; Livewire `Ops\Summary`, `Ops\Ask`, Home card and button; profile settings section; Settings defaults.
- Translations for every new text in `lang/en.json`, `lang/ar.json`, `lang/ckb.json`.
- Tests (Pest): each fact respects permissions and branch scope; money converted with snapshots; summary sent once per day at the person's time, skips empty / Fridays when chosen; push text has no patient names; each quick question; code lookup for every code type; keyword matching in the three languages.
- Docs: README, CHANGELOG, `docs/TEST-SITE.md` (how the doctor tries it).

## 5. Build order

1. Facts engine + permissions (with tests).
2. Morning summary: builder, saved summaries, notification, scheduler, settings, admin page, phone card and page.
3. Quick answers: question catalogue, answer cards, code lookup, keyword matching, admin page, phone screen.
4. Translations, docs, full test run, check in the browser (English and Arabic, phone width), commit.
5. Update the test site (new release zip) so the doctor can try it.

## 6. Not included

- Understanding free typed sentences (paid AI) — can be added later: the facts above become the AI's tools, so no rework.
- Sending by WhatsApp or SMS.
- New reports or questions beyond the lists above (each one is a small separate job).
- Setting up a mailbox for email delivery (needs a mailbox from the hosting).

## 7. Questions for the doctor

1. At what time should the summary arrive? Same for everyone?
2. Who besides him should receive it (accountant, branch managers)?
3. Skip Fridays (or another day off)?
4. Does he want it by **email** too? If yes, which address?
5. Anything he checks every morning that is missing from §2 or §3?
