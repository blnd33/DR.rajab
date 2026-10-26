# Phase 5 — Insight & polish · Plan

> Status: **Building** — straight after Phase 4 (the client asked on 2026-10-26 to run the remaining phases back-to-back); this plan records the scope and the decisions taken.
> Brief scope: *Reports (Excel + PDF), printed documents, full dashboards, backups & system health, performance pass, translation review, final QA.* Modules 24 (reports), 26 (dashboards), 27 (printing), 28 (backups & health) + the open polish items from Phases 1–4.
> Tables: [DATABASE.md](../DATABASE.md) § Phase 5.

## 1. Decisions

| # | Topic | Decision |
|---|---|---|
| P5-1 | **Reports hub** | A *Reports* navigation group with one Filament page per report; each has filters (period, branch in scope, and more where useful), a table with totals, **Export to Excel** (`reports.export`, maatwebsite/excel) and **Print / PDF** (P5-3). Profit numbers need `reports.profit`; amounts use the stored cost and snapshot, so a report never moves when rates change, and are shown in a chosen display currency (USD by default) converted through each row's own snapshot. |
| P5-2 | **Reports in this phase** | Stock value (branch × category, true cost, USD); expiry (by level, branch, product); stock movements (period, type, branch, product); sales & profit (revenue − true cost per product / branch / month); surgeries (per doctor / hospital / type, implants used and their value); purchases & supplier aging (0–30 / 31–60 / 61–90 / 90+ days past due, per supplier and currency); cash (box movements per day, closings and differences); expenses (category × branch × month); kits (checkouts, overdue, unaccounted). |
| P5-3 | **Printed documents** | Print-ready **HTML** pages (A4, the company header and logo from Settings, RTL in Arabic / Sorani, codes LTR, dates YYYY-MM-DD, digits per language) behind the same permission as the document, opened in a new tab and printed or saved as PDF by the browser (same approach as labels, P1-2). **Download PDF** renders the same page with Browsershot when Chrome is available on the server; without it the button opens the print page. Documents: sales invoice, goods received note, transfer note, surgery report (implants with serials / lots, for hospital billing and the patient file), kit checklist, supplier statement, cash closing sheet, every report. |
| P5-4 | **Stock value history** | Nightly snapshot of stock value per branch and currency (+ USD) into `stock_value_snapshots` (a table, so it works on SQLite too; on PostgreSQL it replaces the planned materialized view). Feeds the dashboard trend and the stock value report "as of" a past date. |
| P5-5 | **Dashboards** | Super Admin dashboard completed: monthly sales vs cost (12 months), surgeries per month, stock value trend, top used implants, alerts list (expired, low stock, overdue kits, overdue invoices, cash differences, failed backups). Branch users keep the cards of their branch. Charts with Filament chart widgets; numbers in the user's digits. |
| P5-6 | **Backups & system health** | `system.health` page: last successful backup and its size, backup destinations, queue (pending / failed jobs), scheduler heartbeat (each scheduled job records its last run), disk space, exchange-rate age, app / PHP / database versions. Backup failures and a missing heartbeat notify the Super Admin. `docs/RESTORE.md` checked against the real commands. |
| P5-7 | **Performance pass** | Lazy loading forbidden outside production (`Model::preventLazyLoading`) and every N+1 it finds fixed; indexes checked against the report and list queries; heavy reports and Excel exports run on the queue with a notification when ready; settings and permission lookups cached per request. |
| P5-8 | **Phone dates** | Every native date field on the phone shows the chosen date as YYYY-MM-DD next to it (the Phase 3 surgery-form fix, everywhere). |
| P5-9 | **Translation review** | A short glossary (`docs/i18n-GLOSSARY.md`: one Arabic and one Sorani word per business term) and a pass over ar / ckb for the same term everywhere; a test that ar / ckb values are not left in English (codes and units excepted). Native-speaker review still recommended before go-live. |
| P5-10 | **Final QA** | Every admin and phone screen in 3 languages (smoke tests), a permission sweep (each resource refuses a user without its permission and hides other branches), browser run-through of the main flows end to end, Larastan, Pint, build. |

## 2. Build list
* **Migration** `2026_10_30_*_p5_*`: `stock_value_snapshots`, `scheduler_heartbeats`; report indexes.
* **Services** (`app/Services/Reports`): one query class per report (filters → rows + totals), `DisplayCurrency` conversion through row snapshots, `StockValueSnapshotter`, `DocumentRenderer` (HTML + optional Browsershot), `SystemHealth`.
* **Admin**: Reports group (9 pages), print buttons on the documents, dashboard widgets, System health page.
* **Ops**: date echo on every date field; print links where the phone shows a document.
* **Scheduler**: `reports:snapshot` (nightly), heartbeats on every scheduled job.
* **Tests**: each report's totals against known demo data, display-currency conversion with old snapshots, Excel export, print pages (permission + RTL markup), snapshotter, health page, permission sweep, translations not left in English, screens in 3 languages.
