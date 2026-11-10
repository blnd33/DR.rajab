# Changelog

All notable changes per build phase.

## [Hostinger: PHP 8.4 and WAL] — 2026-11-10

### Fixed
- **Imports on the test site failed with "database is locked".** Laravel applies `DB_TRANSACTION_MODE=IMMEDIATE` only on PHP 8.4, and Hostinger's command-line `php` is 8.3, so a queue job that met a page writing at the same moment failed at once. Every server command and the cron now use `/opt/alt/php84/usr/bin/php`; `deploy/hostinger/env.hostinger` adds `DB_JOURNAL_MODE=WAL` (pages read while the queue writes).
- `deploy/hostinger/update.sh` picks PHP 8.4 by itself when the server has it, prints which PHP it uses and warns when it is older than 8.4.
- [docs/HOSTINGER.md](docs/HOSTINGER.md): the `php` alias for 8.4, the cron in hPanel step by step (no *once per minute* preset; leave other sites' jobs alone; *View Output*), the two import steps (check → *Import N rows*), how to see where an import stands, and how to start a failed import again (rows already written are skipped).

## [Test site for the doctor] — 2026-11-08

Guide: [docs/TEST-SITE.md](docs/TEST-SITE.md) · server files: [deploy/test-site/](deploy/test-site/).

### Added
- **Test site** on a subdomain of the same server, fully separate from the real site (own folder, database `orthotrack_testsite`, Redis databases and prefixes, queue worker, scheduler, session cookie, push keys, backup folder; hidden from search engines). `ORTHOTRACK_TEST_SITE=true` makes it **start with zero data** (base data only, never demo data), shows an orange *Test site* strip on every page of the admin panel and the phone app (three languages).
- `php artisan orthotrack:create-admin <username> --name= --locale=` — creates a Super Admin from the command line (password asked twice, same password rules as the app). For the first login on any new or emptied site; replaces "use tinker" in the production steps.
- `php artisan orthotrack:reset-test-site` — erases all data and uploaded files of the test site and puts the base data back, after typing the site name; refused on any site without `ORTHOTRACK_TEST_SITE=true`.
- `DatabaseSeeder::BASE` — the list of base seeders, shared by the seeder and the reset.
- **Hostinger web hosting** ([docs/HOSTINGER.md](docs/HOSTINGER.md)) for **drrajab.coda-agency.net**: SQLite instead of PostgreSQL (one file outside the website folder; 10 s busy wait, IMMEDIATE transactions), sessions / cache / queue in the database, the one-minute cron also works the queue (`ORTHOTRACK_QUEUE_VIA_SCHEDULER`), backups copy the SQLite file, PDF download off. `php deploy/hostinger/make-release.php` builds the upload zip (code + production packages + built screens, ≈34 MB); `deploy/hostinger/update.sh` updates it keeping data and uploads. The package was installed from the zip with the guide's commands on an empty SQLite database in production mode: base data only, the banner, and the main admin pages and phone app answer.
- **Tests**: empty start, create-admin (success, duplicate, weak password), reset refused (not a test site, wrong name), banner only on a test site. The full reset was checked by hand on a separate database (it cannot run inside the test transaction).

## [Graphs, transfer photos, sales split] — 2026-11-08

Plan: [docs/phases/GRAPHS-PHOTOS-SPLIT-PLAN.md](docs/phases/GRAPHS-PHOTOS-SPLIT-PLAN.md) (decisions G-1 … G-4, T-1 … T-4, S-1 … S-5).

### Added
- **Graphs** (admin panel only, new *Graphs* menu, `reports.view`; cost / profit graphs also need `reports.profit`): 31 graphs in six sections — Sales, Surgeries, Items & stock, Money, Transfers, Kits — as pies (doughnut), ranking bars, monthly bars and lines. A search box finds a graph by words in any of the three languages (e.g. *surgeries hospital*, *dinar*); section chips narrow the list. Each graph has period, branch and currency filters (dates YYYY-MM-DD) and a table under it with every value, its share % and the total. Graphs use the reports' own numbers, so a graph never disagrees with its report. Colours come from the validated colour-blind-safe palette (light and dark steps); past 7 slices the rest fold into a grey *Other*.
- **Draw as Pie / Bars / Line** on every graph (client, same day): each graph opens in its own form and can be switched; graphs over time also offer a line. The choice is kept in the link (`?view=bars`). A pie is greyed out, with the reason, when it would mislead — negative numbers (a month with a loss) or values that are not parts of a whole (stock value over time). A pie of a graph with two series (sales and cost) shows the first and says so.
- **Transfer photos**: the sending branch must take a photo of the items before *Send* (*our photo*), and the receiving branch a photo on arrival before *Confirm received* (*their photo*). Both show side by side on the transfer (phone and admin), with who took them and when; only people who may see the transfer can open them. Closing a branch (transfer everything) asks for the photo too. Images only, 10 MB max, never deleted.
- **Sales split by percentage**: a sale is priced *per item* (as before) or with *one price for all the pieces* in USD, EUR or IQD. The price is split by each piece's share of the cost (e.g. cost 250 / 150 / 100 of 500 → 50 % / 30 % / 20 %; sold for 800 → 400 / 240 / 160); the shares can be edited and must add up to 100 %. Pieces with no cost share equally; the last line takes the rounding rest so the lines add up exactly. A live table shows each piece's cost %, share and amount (cost amounts only with `stock.view_cost`). Every sold line now stores its **cost %** and **received %**.
- **Sales and profit report**: new *Sale line (each piece, with %)* grouping (invoice, piece, cost, cost %, received, received %, profit, margin) and *Revenue %* / *Cost %* columns in every grouping. The invoice page shows the pricing and both percentages per line.
- **Tests**: every graph builds in three languages, search, folding into Other, profit graphs hidden, page access; photos required to send and receive, who may add which photo, photo access, phone flow; split amounts and rounding, edited shares, zero-cost pieces, refused shares, report percentages, admin and phone screens.

### Deploy notes
- `php artisan migrate` (attachment kinds, `sales_invoices.pricing`, `sales_invoice_items.cost_share` / `price_share`), `npm run build` (chart palette plugin), `php artisan view:cache`.
- Photos are stored on the private `local` disk under `transfers/{id}` — include it in backups.

### Known gaps / to review
- Arabic and Sorani texts for this part were written by the developer — native-speaker review needed.
- Sales made before this update have no stored per-piece cost % / received % (shown as —); the report's Revenue % / Cost % columns still work for them, as they are worked out from the amounts.

## [Lima / Enovis catalog] — 2026-11-07

Plan: [docs/phases/LIMA-CATALOG-PLAN.md](docs/phases/LIMA-CATALOG-PLAN.md) (decisions L-1 … L-10, questions for the doctor).

### Added
- **Supplier catalog import** (Admin → Excel imports → *Supplier catalog (Lima / Enovis workbook)*, `products.import`): reads the doctor's own workbook as it is — Hip, Knee and Shoulder implants, instrument-set summaries and set details. Implants become products (lot-tracked, expiry required, category *Area → section*, brand Lima or Dedienne Santé for SYMBOL items, size from the name, EAN-13 codes as GTIN); sets become kit types with their contents; instruments become instrument types. Obvious typos in the names are fixed (System, Spacer, Ti6Al4V, …). Same preview as every import (new / changed old → new / errors), with the sheet and row of each line. Re-importing the same file changes nothing; a recoloured item updates its usage.
- **Usage level** on products and kit types, read from the workbook's colour key per sheet (Most used, Less used, Sometimes, Rarely, Not used). Every item stays in the system and usable, *Not used* (red) ones included — they are labelled and sort last. Filter and sort by usage in Products and Kit types; product searches on the phone show the most used first.
- **SN or LOT per box**: lot items may carry the box's own serial number. Receiving (phone and admin) shows *Serial (SN)* and *Lot (LOT)*, both optional — staff fill whichever the label shows. A box with an SN is kept as its own record of 1 and scanning its SN (GS1 or typed) finds that exact box; boxes with only a LOT add up per lot. An SN already in stock is refused. Transfers, kits, corrections keep the SN; lists show *SN … · LOT …*.
- **Learn the box barcode on first scan** (`products.link_barcodes`, given to roles that can receive stock): Lima boxes carry a GS1 GTIN, not the REF. When Receive stock meets an unknown GTIN it asks *which product is this?* (search by REF), links the barcode once and adds the box with its lot and expiry; next boxes scan straight in. The Scan screen offers *Receive and link this box* for an unknown box.
- **Tests**: workbook reader rules, full import, unchanged re-import, recolour → update / archive, wrong file refused, usage order; barcode learning (link, extra barcode, no permission, barcode owned by another product).

### Changed
- Queue `retry_after` defaults 90 → 3700 s (longer than the 1-hour import job) for the database and Redis queues. Imports raise PHP memory to 512 MB and lift the time limit while they run.

### Deploy notes
- `php artisan migrate` (usage columns, new permission, optional serial on lot boxes), `npm run build`, `php artisan icons:cache` if icons are cached; restart Horizon so the new `retry_after` applies.

### Known gaps / to review
- One workbook conflict: **5885.42.262** is listed as two different items — the import stops it with an error until the doctor says which is right.
- Prices come after go-live (decided 2026-11-07); names stay English only. Arabic and Sorani texts for this part need native-speaker review (e.g. Sorani *Hip*).

## [Phase 5 — Insight & polish] — 2026-10-27

Plan: [docs/phases/PHASE-5-PLAN.md](docs/phases/PHASE-5-PLAN.md) (decisions P5-1 … P5-10; built straight after Phase 4).

### Added
- **Reports** (new *Reports* menu, `reports.view`, branches in the user's scope): stock value (branch × category, or the nightly history), expiry levels, stock movements, sales and profit, surgeries, supplier aging (not due / 1–30 / 31–60 / 61–90 / 90+ days), cash (start, in, out, closing differences, end per box), expenses (category / branch / month, split costs counted per branch share) and kits. Filters above the table (period, branch, display currency, grouping), sortable columns, a totals line. Every amount converts with the rates it was booked with, so a report never moves when rates change; cost, profit and margin need `reports.profit`.
- **Excel export** of every report (`reports.export`) with the totals line, codes and dates as text, right-to-left sheets in Arabic and Sorani.
- **Printed documents** (A4, company name, address and logo from Settings, RTL in Arabic / Sorani, codes LTR): sales invoice, goods received note, transfer note, surgery report (implants with serials and lots), kit checklist, supplier statement, cash closing sheet — and every report. *Print / PDF* opens the print page; **Download PDF** renders the same page with headless Chrome (Browsershot) so Arabic and Sorani shaping is exact. Each document needs the same permission as its screen.
- **Nightly stock value history** (`stock_value_snapshots`, `php artisan reports:snapshot` at 23:50).
- **Dashboard**: sales vs cost per month (12 months, cost only with `reports.profit`), surgeries per month, stock value trend (90 nights), most used implants (90 days), and a *Needs attention* strip for system problems. Chart colours were checked with the palette validator (colour-blind safe, both themes).
- **System health** (System → System health, `system.health`): backups (last backup, size, storage reachable), every daily job (last run, failed, late — scheduler heartbeats), queue (failed jobs), disk space, exchange-rate age, versions; *Run a backup now*. Problems show on the dashboard and as a menu badge.
- **Phone date fields** show the chosen date as YYYY-MM-DD under the field (device formats differ).
- **Translation glossary** ([docs/i18n-GLOSSARY.md](docs/i18n-GLOSSARY.md)) — one Arabic and one Sorani word per business term, generated from the language files.
- **Tests**: every report opens, prints and exports in 3 languages; hand-checked totals (supplier aging, expense shares, sales); profit columns and branch scope; nightly snapshot; every document prints in 3 languages with the right direction and refuses other branches; health checks (late / failed jobs, missing rates); dashboard widgets in 3 languages; a **permission sweep** (a panel-only user is refused on every resource, report, print page and the health page); a **query budget** for 26 main screens; no Arabic / Sorani string left in English.

### Fixed
- Composer could not rewrite the autoloader while the local preview server was running (Windows keeps the files open) — new packages looked installed but were not loadable. Stop the preview server before `composer require` / `dump-autoload`.

### Changed from the plan
- Report Excel exports run straight away (they take well under a second at this size) instead of on the queue.
- Lazy loading was already forbidden outside production since Phase 0; the performance pass measured every main screen instead (2–32 queries) and fixed the budget in a test.

### Known gaps / to review
- PDF downloads need Chrome / Chromium on the server (`PDF_CHROME_PATH`, or auto-detected) and `npm install` (puppeteer, without its own Chromium); without them the print page's *Save as PDF* is the fallback.
- Chart axis numbers use Western digits (Chart.js); labels and tooltips' titles follow the language.
- Arabic and Sorani texts for Phase 5 were written by the developer — native-speaker review needed before go-live (the glossary is the place to start).

## [Phase 4 — Money] — 2026-10-26

Plan: [docs/phases/PHASE-4-PLAN.md](docs/phases/PHASE-4-PLAN.md) (decisions P4-1 … P4-17; built straight after Phase 3).

### Added
- **Exchange rates**: USD → IQD (typed by a permitted user) and EUR → USD (typed, or fetched daily from the ECB as a suggestion to confirm — or confirmed automatically when the setting says so). Rates are append-only; each confirmation makes a new immutable *rate snapshot*. Every money row stores its currency and snapshot, so an amount converts the same way forever (USD is the pivot). Money screens refuse to save before both rates exist.
- **Cash boxes & bank accounts** per branch and currency (head-office boxes have no branch), with an opening balance. One money ledger (`money_transactions`, append-only, `MoneyLedgerService` only): signed amount, USD value, running balance, reversing rows for corrections; a cash box never goes below zero (a bank account may, when allowed).
- **Daily closing**: count the drawer (optional notes count), see the difference, explain it; *submit* locks the closing, books the difference so the box matches the drawer, opens a *cash difference* issue and tells the reviewers. Reviewed in **Finance → Daily closings**.
- **Moving money**: same-currency transfers between boxes / branches (send → receive, cancel reverses); **currency exchange** of own money at the deal rate with the gain / loss against the day's reference rate (in USD).
- **Purchases (supplier invoices)**: number unique per supplier, invoice / due date (default = supplier's payment terms), currency, receiving branch, lines, invoice discount spread over the lines (the *true unit cost* is what was paid), invoice file. *Confirm* → payable on the supplier's statement; *Receive* in one or more deliveries by scanning or typing (serial, lot, expiry) through `StockMovementService` — every unit / lot remembers its purchase line, so each purchase keeps its own cost layer; *Cancel* only while nothing was received, paid or costed.
- **Landed costs**: shipping, customs, clearance, bank fees (any currency) spread by value or quantity over the lines; units and lots still in stock get the extra cost, the part on items already used or sold is kept as cost of sales; an optional payee (forwarder / broker) gets a payable.
- **Supplier payments** from a box or bank (wire, hawala, cash, cheque), allocated to the oldest open invoices or to a chosen one; payments in another currency store the rate and the realised exchange gain / loss. Above the threshold (setting, USD) the payment waits for approval before money leaves. Void puts the money back and reverses the statement.
- **Supplier statement**: supplier page with *We owe* per currency, the running statement and their purchases; dashboard card and daily reminder for overdue invoices.
- **Credit notes**: *Record supplier credit* on a sent supplier return lowers what we owe.
- **Approvals queue** (**Finance → Approvals**): large supplier payments and expenses (and commission payouts) — approve or reject with a note; nobody approves their own request (except the Super Admin).
- **Sales**: scan units / lots, hospital or walk-in buyer, currency, list price (converted at today's rate) or typed price, discount, near-expiry / FEFO override reason; paid now into the branch box. *Void* puts items back on the shelf and reverses the money. Credit sales + customer payments (allocated to open invoices) only when the *credit sales* switch is on; the customer page gets a statement.
- **Expenses**: translatable categories (14 seeded in every environment), paid from a box (branch or head office), payee, receipts; above the threshold → approval; head-office costs can be split over branches (shares = 100 %). **Recurring expenses** (monthly, day 1–28) are created every morning, posted or waiting for approval per rule.
- **Doctor commissions** (behind the *commissions* switch, hidden otherwise — for Super Admins too): rules per doctor / surgery type / product or category, fixed or % — earned when a surgery completes, approved, paid from a box.
- **Dashboard**: *Money* — money in boxes and banks, owed to suppliers (one USD total at today's rates, split per currency underneath), overdue supplier invoices, approvals waiting for you; stock value now one USD total.
- **Phone app (cashier)**: **Cash** tile on Tasks → box balances, money on its way (receive), latest movements; **New sale** by scanning with live total; **Record an expense** with a receipt photo; **Exchange currency** with today's reference rate; **Close the day** with a live difference.
- **Scheduler**: `php artisan money:daily` at 07:00 — ECB rate suggestion, recurring expenses due today, overdue supplier invoice reminders.
- **Older documents**: write-offs get their USD cost and snapshot on approval; supplier returns, surgeries (fee) and stock costs carry snapshot columns.
- **Demo data**: `DemoMoneySeeder` — rates, a head-office bank and three cash boxes, a NovaJoint invoice in EUR (discount, partly received, shipping + customs, partly paid), an overdue BoneTech invoice, a large payment waiting for approval, a draft, a cash sale, four expenses (one split, one waiting), a recurring rent, an exchange, a transfer on its way and a closing five dollars short.
- **Tests**: ledger, closings, transfers, exchanges, rates and snapshots, purchases (true cost, receiving, landed costs on stock and on sold items, cross-currency payments with gain / loss, approvals, statements, credit notes), sales (cash, credit, void), expenses (approval, split, recurring), commissions, every finance screen in 3 languages, branch money scope, feature switches, phone closing and counter sale.

### Fixed
- Cash box list totals summed balances as floats — now summed in the database (decimal).
- View pages (transfers, counts, requests, write-offs, returns, kits, surgeries, purchases…) showed old values after an action until the page was reloaded — Filament kept the infolist built with the previous record; `RefreshesInfolist` rebuilds it.
- Receiving a purchase by scan took a product barcode / SKU as the serial of a serial-tracked item; only a real serial counts now (otherwise the scan asks for it).
- Pay on a purchase prefilled the open amount in the invoice currency; it now fills it in the chosen box's currency at today's rate, keeping the method and date defaults.
- Date fields defaulted to the server's (UTC) day; they use the business time zone (Asia/Baghdad).
- Phone exchange screen: the reference rate kept 8 decimals (IQD → USD was rounded to 0.0007) and starts from the USD box.

### Changed from the plan
- No `shipments` table yet (landed costs hang on the purchase); approvals cover supplier payments, expenses and commission payouts — write-offs and stock requests keep their own Phase 2 flows.
- Supplier payment statuses are *pending approval → posted* (no separate *approved* step) plus *rejected* / *voided*.
- Printed invoices, receipts and statements come with the Phase 5 PDFs.

### Known gaps / to review
- The ECB fetch needs outbound internet on the server; without it the EUR rate is typed by hand.
- Arabic and Sorani texts for Phase 4 were written by the developer — native-speaker review needed before go-live (money words especially).

## [Phase 3 — Clinical & assets] — 2026-10-26

Plan: [docs/phases/PHASE-3-PLAN.md](docs/phases/PHASE-3-PLAN.md) (decisions P3-1 … P3-12; built straight after Phase 2).

### Added
- **Patients** (sensitive): file number (generated or typed), name, phone and notes **encrypted at rest**, phone found through a hash (`0750…` and `+964 750…` match), age or date of birth, gender, primary doctor. Doctors see only their own patients (primary doctor or operated) unless they have *View all patients*; every opening of a file is in the activity log; never deleted. Surgery history with the serials used, for follow-ups and recalls.
- **Surgery logging**: type (configurable, translatable list), date, branch, hospital, lead surgeon + assistants, patient (pick or new), side, kit checkout, notes, photos of the implant stickers. *Complete* takes everything out of stock in one transaction (units → used in surgery at that hospital, lots reduced, `surgery_use` rows with cost). Units from another branch or another kit are allowed with a warning on the line (and a notification); expired items need *override expired* + a reason; a serial can be used in one live surgery only. *Cancel* (with reason) puts every item back on the branch shelf (`surgery_reversal`).
- **Offline surgery drafts** (phone): the form lives in the browser — every change is kept in IndexedDB, Save / Complete queues the draft, it is sent to `POST /app/sync/surgeries` now or when the connection is back (from any page). The server updates the same draft for the same `client_uuid`, ignores replays of a completed surgery, reuses a patient with the same phone and name, and keeps anything that blocks (missing hospital, unknown code, used serial, expired without permission) as a draft with the reasons to fix online. *My surgeries* shows drafts still only on the phone.
- **Kits & instruments**: kit types with a template (instrument types + implant sizes, required / optional), physical kits (`KIT-<branch>-0001` code + barcode, home and current branch, status, photo), instruments (`INS-…`, maker serial, holder, maintenance, missing / found, retire). Implants in a kit stay stock of the branch (units *in a kit*, lots in the kit's own bucket). Kit and instrument changes go through `AssetMovementService` into an append-only `asset_movements` ledger (history tab).
- **Kit flow**: prepare / refill by scanning (instrument labels, serials, lot boxes — FEFO) with a live comparison against the template; check out to a hospital (doctor, planned date, optional patient; due back after the *overdue-kit days* setting); surgeries take implants from the kit at the hospital; return by scanning — every line reconciled as *returned*, *used in surgery* (found through the logged surgery) or *unaccounted*; missing / damaged instruments flagged. Unaccounted items open one critical issue and notify the Super Admin, who settles each: found (back in the kit), used (surgery logged now) or lost (written off).
- **Kits on transfers**: an available kit travels with everything in it (kit, instruments, implants change branch on receive); loose instruments too. *Move all stock* (branch closing) includes kits and instruments; closing is blocked while kits, instruments, kits out or draft surgeries remain.
- **Overdue kits**: daily in `stock:alerts` (notification per branch), badges and filters, dashboard count.
- **Hospitals**: detail page with kits lent, surgeries and returns.
- **Dashboard**: *Surgeries and kits* — surgeries today / this month, kits out (overdue), unaccounted implants.
- **Phone app**: Tasks gets *New surgery*, *My surgeries*, *Kits*, surgery drafts and kits out; kit screens (contents vs template, prepare / refill by scan, check out, return by scan); reusable `<x-ops.scan-box>`.
- **Demo data**: `DemoClinicalSeeder` — 4 surgery types, 4 instrument types, knee and hip kit types with templates, 3 kits (one complete, one out at Rizgary and overdue, one only instruments), 5 instruments, 4 patients, a surgery from the shelf, a surgery from the kit, a draft missing its hospital.
- **Tests**: surgeries (complete, drafts with problems, idempotent replay, patient reuse, warnings, expired + override, cancel, permissions, sync endpoint), patients (encryption, phone hash, own vs all, view log), kit flow (template, prepare, checkout → surgery from kit → return → unaccounted → resolve, damaged instruments, kit transfer, overdue reminder, instruments in kits), live screens in 3 languages and privacy between doctors — 213 tests in total.

### Changed from the plan
- Unaccounted items settled as *lost* are written off at once (the Super Admin's decision is the approval) instead of opening a pending write-off.
- `surgeries.problems` (jsonb) and `surgery_items.used_at` added; the serial uniqueness index covers used lines only, so two drafts may hold the same serial until one completes.
- Kit / instrument codes have no year (`KIT-DHK-0001`).
- Printed surgery reports and kit checklists come with Phase 5 (PDFs), as planned.

### Known gaps / to review
- Native date fields on the phone show the device's date format; the chosen date is echoed as YYYY-MM-DD on the surgery form — the other phone date fields get the same treatment in Phase 5.
- Surgery photos are added online (not stored offline).
- Arabic and Sorani texts for Phase 3 were written by the developer — native-speaker review needed before go-live.
## [Phase 2 — Movement] — 2026-10-26

Plan: [docs/phases/PHASE-2-PLAN.md](docs/phases/PHASE-2-PLAN.md) (decisions P2-1 … P2-10; built straight after Phase 1 at the client's request).

### Added
- **Transfers:** draft → add units / lot quantities (scan or pick) → send (units *in transit*, lots leave the shelf; still on the sender's books) → receive by scanning. Missing, short and extra lines are recorded, the transfer becomes *received with differences* and an issue opens; the Super Admin settles each line (*found and received*, *returned to the sender*, *written off as lost*) and the issue closes by itself. Cancel before receiving puts everything back. Expired items need *override expired* + a reason. A unit can only sit on one open transfer or write-off.
- **Stock requests:** a branch asks for products (normal / urgent, needed-by date, preferred source); the approver sets quantity and sending branch per line (suggestion: the branch with the most valid stock); approval creates one draft transfer per sending branch and tells them; the request is *fulfilled* when its transfers arrive. Reject with reason; the requester can cancel while pending.
- **Write-offs:** report damaged / lost / expired / other with photos → approve or reject. Stock moves only on approval; the cost per currency is shown.
- **Returns:** customer returns (units out of our stock or lot quantities) — *good* goes back on the shelf, *damaged* goes back and opens a pending write-off; the unit's origin is kept. Supplier returns: draft → send (units *returned to supplier*, lots off the shelf), one currency per return; the credit note follows in Phase 4.
- **Stock counts:** whole branch / one category / one shelf; snapshot of what should be there; phone scanning with an **offline queue** (IndexedDB, `client_uuid`, `POST /app/sync/count-scans`, replays ignored); lot counts typed in; submit → missing / quantity differs / belongs to another branch / not in the system → decide per line (*adjust* = count adjustment on the ledger, *write off* = pending write-off, *investigating*, *ignore*) → close.
- **Issues inbox** (admin): transfer and count differences in one list, with links to the document, resolve / dismiss with a note.
- **Hospitals & customers** master data (Phase 3 kits / surgeries and Phase 4 receivables use it).
- **Branches:** closing is blocked by open transfers, requests, counts and write-offs; *Move all stock to another branch* sends everything on the shelves in one transfer.
- **Low stock:** *Request from another branch* per row (pre-filled request).
- **Dashboard:** *Waiting for action* — write-offs and requests to approve, transfers in transit, counts to review, open issues.
- **Notifications** (in each user's language): transfer on its way / received / received with differences, request waiting / approved / rejected / transfer to prepare, write-off waiting / decided, count ready for review.
- **Phone app:** bottom bar Home · Stock · **Scan** · Tasks · Me; Tasks hub (to receive, to send, my requests, counts, write-offs waiting) with new transfer, request, count, write-off and customer-return screens.
- **Permissions:** `issues.view_any`, `issues.resolve`; attachments are served through a permission check (`/attachments/{id}`).
- **Demo data:** `DemoMovementSeeder` creates a transfer in transit, one received with a difference, a draft, an urgent request, a write-off, a customer and a supplier return, a running and a submitted count, and three hospitals / clinics — all through the real services.
- **Tests:** transfers (send, receive, differences, settling, cancel, expired, branch close), requests → transfers → fulfilled, write-offs, both returns, counts (snapshot, idempotent sync endpoint, decisions, scope, live lot counts), every new screen in 3 languages and branch refusals — 185 tests in total.

### Fixed (from Phase 1)
- Arabic plurals: Laravel picks six Arabic forms (0, 1, 2, 3–10, 11–99, 100+); the two-form strings showed the singular for 3+ items. All plural strings now have six forms.
- Lots list filter *Only with quantity* did nothing (Filament passes the query by argument **name** — closures must use `$query`).
- Phone: the hint inside code fields uses the normal font (Kurdish / Arabic letters were split by the code font); product sizes no longer break across lines.

### Changed from the plan
- Stock-movement methods are split per item type (`sendUnit` / `sendBatch`, …) instead of the generic names in the plan.
- `issues.title` added (readable summary) next to `details`.
- `transfer_assets` (kits on transfers) moves to Phase 3 with kits (P2-8, as planned).
- `phpunit.xml` raises `memory_limit` to 1G for the full suite.

### Known gaps / to review
- Supplier credit notes, write-off cost in one currency and `fx_snapshot_id` columns arrive with Phase 4.
- Arabic and Sorani texts for Phase 2 were written by the developer — native-speaker review needed before go-live.
- Offline queue covers stock-count scans; offline transfer receiving stays online-only (receive needs the server's verdict per unit).
## [Phase 1 — Core Inventory] — 2026-10-26

Plan: [docs/phases/PHASE-1-PLAN.md](docs/phases/PHASE-1-PLAN.md) (approved P1-1 … P1-6).

### Added
- **Catalog:** products (SKU, GTIN, names in EN/AR/KU, category tree, brand, size, unit, serial or lot tracking, implant flag, prices + currency, default minimum, photo), extra barcodes per product, minimum / maximum / reorder quantity per branch, categories, brands, units, suppliers (master data; payables in Phase 4). Tracking type locks once a product has stock.
- **Stock:** serial units (one row per implant, globally unique serial) and lot buckets (quantity per product × branch × lot × expiry × shelf × cost). Shelves per branch. Statuses and locations from the brief.
- **Stock ledger:** `StockMovementService` is the only writer — permission + branch check, `DB::transaction` + `lockForUpdate`, one `stock_movements` row per change, append-only (database trigger + model guard). Phase 1 movements: opening stock, Excel import, corrections (lot / expiry / cost / supplier / shelf, with reason and old → new on the row), Super Admin status correction. Concurrent receipts of the same serial or bucket are handled (unique index + savepoint retry).
- **Expired items:** recorded with a warning, blocked from use (`assertUsable`) unless the user has *override expired* in that branch and gives a reason — ready for surgeries, sales and transfers.
- **Receive stock** (admin and phone): scan box after box — GS1 fills product, lot, expiry and serial; scanning a lot again adds 1; product defaults fill the cost.
- **Barcodes:** parser chain in `config/orthotrack.php` (`scan_parsers`): our own labels → GS1 (DataMatrix / GS1-128, `]d2` `]C1` `]Q3` prefixes, GS / `<GS>` separators, bracketed form, AIs 01 10 11 17 21 + extras, YYMMDD century rule, day 00, check digit) → plain codes (serial, SKU, GTIN-8/12/13/14, extra barcodes, lot numbers). Unknown codes are saved with their bytes (hex) under *System → Unrecognised scans*.
- **Labels:** templates (size in mm, Code 128 / DataMatrix / GS1 DataMatrix / QR, printed fields, text size); print sheet sized to the label for thermal printers (one label per page), from products, units and lots (single or bulk, copies).
- **Expiry levels:** editable list with color picker, labels in 3 languages, live preview; validation (exactly one "Expired" fixed at ≤ 0 and blocking, no gaps, no overlaps, open-ended last level); readable text color computed per color; optional "blocks usage" and "notify on enter" per level. Expiry chips (color + label + days left + date) everywhere.
- **FEFO:** soonest valid expiry first for units and lots (skips expired and blocking levels); lot quantities allocated across buckets.
- **Serial history & recall:** unit timeline from the ledger (admin and phone); recall search by lot (exact, then partial) across all branches with status and location.
- **Low stock:** `v_stock_on_hand` / `v_low_stock` views, admin page with inline minimum edit and navigation badge, phone filter, dashboard card.
- **Alerts:** daily `stock:alerts` (06:00 Baghdad): items entering a new expiry level and low stock → one digest per branch, in-app + web push, in each user's language.
- **Excel import / export:** templates (Data + Instructions sheet in English, Arabic, Kurdish) for products, stock and minimum stock; upload → check every row → preview with counts and old → new per field → confirm → queued import with progress → error file; original file kept; every step in the activity log. Rules from the brief: matched by SKU / serial, empty cells never overwrite, no branch or status changes, used / sold units rejected, serial = quantity 1, Excel serial dates and text dates, Arabic-Indic digits normalised, Arabic / Kurdish text intact. Exports in the same column format.
- **Dashboard:** stock value per currency (true cost), below minimum, expired, next level, expiry × branch table in the configured colors.
- **Phone app:** bottom bar Home · Stock · **Scan** · Alerts · Me; Scan resolves every code (unit / product / lot / unknown) with FEFO hint, wrong-branch and expired warnings, success / error beep from the server's answer; Stock list with expiry-level and below-minimum filters; product, unit (history), recall and receive screens; Home tiles for the branch.
- **Branches:** shelves tab; closing is blocked while the branch holds stock.
- **Permissions:** new `stock.receive`, `stock.correct`, `labels.templates`; Branch Staff can receive and recall in their branches, Doctors see products and recall, Accountants can correct costs.
- **Seeders:** units, default expiry levels and two label templates (also in production); demo catalog (14 fictional orthopedic products, 3 suppliers, shelves) with stock in DHK / EBL / BGW across every expiry level.
- **Tests:** GS1 vectors (`tests/fixtures/gs1-vectors.json`, shared for future real box scans), ledger rules and concurrency guards, corrections, overrides, append-only, expiry level validation, FEFO, transition notifications, scan resolver, recall, stock value, low stock, branch closing, every Excel rule, template, screens in 3 languages and permission refusals — 157 tests in total.

### Changed from the approved plan
- Lot buckets also key on unit cost + currency (so two deliveries of one lot at different prices keep their true cost); Phase 4 rebuilds the key with the purchase line.
- `stock_movements.details` (jsonb) added for old → new values of corrections.
- Serial, SKU and GTIN matching live in one `PlainCodeParser` instead of separate parsers (same order of checks).
- The daily jobs run as one scheduled command (`stock:alerts`) instead of two queued jobs.
- Excel lot rows: a lot already in stock must have the same quantity (refused otherwise) — Excel never adds to or recounts existing stock; new deliveries go through *Receive stock*.
- Local preview `.env` runs queued jobs immediately (`QUEUE_CONNECTION=sync`); servers keep the queue + worker.

### Known gaps / to review
- ~~One-click "Transfer all stock to branch X" and "Request from another branch"~~ — done in Phase 2.
- Stock value in one currency arrives with exchange rates in Phase 4 (P1-3).
- Arabic and Sorani texts for Phase 1 were written by the developer — native-speaker review needed before go-live.
- Real box labels from the client have not been scanned yet; add them to `tests/fixtures/gs1-vectors.json` when they arrive.

## [Phase 0 — Foundation] — 2026-10-25

### Added
- **Project:** Laravel 13, PostgreSQL, Redis/Horizon, Filament 5 admin panel (`/admin`), Livewire 4 operations app (`/app`), Vite + Tailwind 4, Pest, Larastan (level 5), Pint.
- **Single login** for both panels (username or email) with role-based redirect: Super Admin/Accountant → `/admin`, Doctor/Cashier/Branch Staff → `/app`; panel switcher for users with both.
- **Permissions = what × where:** full permission registry for all phases (`domain.action`), 5 role templates, per-user extra permissions, per-permission branch scope (`user_permission_branches`), `BranchScopeResolver` used by policies and query scopes. Custom role editor grouped by module, labelled in EN/AR/KU.
- **Users:** create/edit, deactivate/reactivate (never deleted), branches + main branch, language, per-user inactivity timeout; device list with "log out this device" / "log out all devices" (admin and self-service); failed-login logging with reasons; login rate limiting; two-factor (TOTP + recovery codes), required for the Super Admin when the setting is on.
- **Branches (base):** create/edit with EN/AR/KU names, close with reason + blocker checklist (Phase 0 checks users; later phases add stock, transfers, cash, kits), reopen, true delete only without records.
- **Languages:** English, Arabic, Sorani Kurdish; full RTL; per-user language; config-only extension (`config/locales.php`); translatable DB fields with side-by-side language inputs; fallback user locale → en → any.
- **Digits:** Arabic-Indic display for ar/ckb (configurable per language), Western storage; input normalisation of Arabic-Indic + Persian digits for forms and Livewire requests; codes always Western/LTR; dates `YYYY-MM-DD`.
- **Activity log:** spatie activitylog + branch, IP, device and searchable reference code; append-only (DB trigger), read-only viewer with filters and before/after view.
- **Settings:** company + logo, digits per language, security (timeout, login attempts, admin 2FA), approval thresholds, overdue-kit days, exchange-rate sources, optional feature flags.
- **Ops app shell:** mobile-first layout, top bar with branch switcher and notifications, bottom nav, offline/sync banner, toasts, install banner (Android prompt + iOS instructions); screens: Home, Scan (camera via ZXing + USB/Bluetooth scanner + manual entry), Notifications, Me (language, devices, push, logout).
- **PWA:** localised manifest, Workbox service worker (app shell + fonts + scanner precached, network-only for business data, offline page), web push wiring, IndexedDB store for offline drafts (used from Phase 2/3).
- **Infrastructure:** append-only trigger function, gap-free document numbering, attachments table, settings repository, idempotency keys for offline sync.
- **Ops:** deploy script, Nginx (HTTPS, camera permission), Supervisor (Horizon), cron, encrypted off-site backups (30 daily + 12 monthly, failure email to Coda), restore guide.
- **Seeders:** currencies, permissions/roles, default settings; demo branches (DHK, EBL, BGW) and demo users per role/language (non-production only).
- **Tests:** login redirect per role, failed/inactive/no-access login logging, device revoke + deactivation + idle timeout, branch scope resolution and policy/query agreement, append-only audit log, branch close/reopen/delete rules, translatable fallback, document numbers, RTL/locale switching, digit display/normalisation, screen smoke tests, translation completeness.

### Changed from the approved proposal
- `filament/spatie-laravel-translatable-plugin` / `lara-zeus/spatie-translatable` **not used**: all languages are shown side by side in admin forms instead of a locale switcher (clearer for staff; the plugin's own README warns about complex fields).
- `bezhansalleh/filament-shield` **not used**: a custom role/permission editor shows the registry grouped by module with translated labels and supports the branch-scope model.
- `laravel/sanctum` not needed: offline-sync endpoints use the normal session + CSRF.
- `laravel-lang` Sorani validation strings had placeholders machine-translated into numbers (`:attribute` → `١٠`); repaired automatically. Their wording still needs native review.

### Fixed during the first run-through (2026-10-25)
- Cache table migration now runs before the permission tables (spatie clears the cache while migrating).
- Ops app access used outdated permission names — every ops user got 403 on `/app`.
- Activity log crashed (strict mode) for subjects without `branch_id` / `serial` columns.
- Login redirect read the session from the request (failed inside Livewire tests).
- Filament JS/CSS assets are now published automatically (`filament:upgrade` on `composer install`).
- Dates in Arabic-Indic digits showed day-first (`٢٥-١٠-٢٠٢٦`) inside RTL text because of the Unicode bidi algorithm; left-to-right marks keep `YYYY-MM-DD` order.
- Greeting keeps a title with the first name ("Dr. Demo" instead of "Dr."); head-office column shows a star only (no red ✗).
- SQLite preview mode for looking at the app before PostgreSQL is available.

### Known gaps / to review
- Arabic and Sorani UI translations were written by the developer — **native-speaker review needed before go-live** (`lang/ar.json`, `lang/ckb.json`, `lang/ckb/validation.php`).
- Placeholder app icon (teal cross) until the client logo arrives.
