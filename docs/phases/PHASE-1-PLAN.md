# Phase 1 — Core Inventory · Plan

> Status: **Approved 2026-10-25 (P1-1 … P1-6). Built 2026-10-26.** What changed while building is listed in [CHANGELOG.md](../../CHANGELOG.md) → Phase 1 → *Changed from the approved plan*.
> Brief scope: *Branches (add/close rules), product catalog, serial & lot stock, barcode scanning + GS1 parser + labels, expiry levels (configurable), FEFO, serial history & recall search, Excel import/export (templates, preview, update rules), low stock alerts.*
> Table shapes are the ones already approved in [DATABASE.md](../DATABASE.md) § Phase 1. This plan says **how** they get built.

---

## 1. Decisions to approve

| # | Topic | Proposal | Why |
|---|---|---|---|
| P1-1 | **How stock enters before Purchases exist (Phase 4)** | Two ways in Phase 1: **(a) "Receive stock" screen** (opening balance — scan the box, GS1 fills product/lot/expiry/serial, type cost) and **(b) Excel stock import**. Both write `opening_balance` / `import` ledger rows. Phase 4 adds purchase receipts as a third way. | The client has existing stock to load at launch (open question 13). |
| P1-2 | **Label printing** | Labels render as **HTML sized to the label** (`@page { size: 50mm 25mm }`) with SVG barcodes from `tc-lib-barcode`, printed through the browser to the thermal printer's driver. **No Chrome/Browsershot on the server in Phase 1**; Browsershot arrives in Phase 5 for multi-page documents (invoices, slips). | Thermal printers (Zebra, Xprinter, Brother QL) print best from a page exactly the label size. Saves installing headless Chrome now. Same templates can be turned into PDF later. |
| P1-3 | **Stock value before exchange rates exist** | Stock value is shown **per cost currency** (e.g. "USD 12,400 · EUR 3,100"). The "value in USD/IQD" column of `v_stock_on_hand` is filled once Phase 4 adds `fx_snapshots`. | The brief forbids converting money without a stored rate. |
| P1-4 | **"Transfer all stock to branch X" when closing** and **"Request from another branch" on low stock** | Phase 1 shows the blocker / the low-stock list; the one-click buttons are wired in **Phase 2** (they create a transfer / stock request, which don't exist yet). | Keeps Phase 1 from building half of Phase 2. |
| P1-5 | **Editing a unit after it exists** | Lot / expiry / cost corrections go through `StockMovementService::correct()` with a required reason → `cost_adjustment` or `status_override` ledger row + activity log. Branch and status **cannot** be changed by edit or Excel (only by movements in later phases). | Matches the brief's import rule and the "ledger for every change" rule. |
| P1-6 | **Excel library** | `maatwebsite/excel` **3.1** (supports Laravel 13; already in the approved package list). Our own `data_imports` / `data_import_rows` tables hold the preview (D7). | As approved. |

---

## 2. Packages to add

| Package | Use |
|---|---|
| `tecnickcom/tc-lib-barcode` ^2.17 | Code128, GS1-128, DataMatrix (incl. GS1), QR → SVG for labels (D4) |
| `maatwebsite/excel` ^3.1 | Read uploads in chunks, write templates / exports / error files |

No new npm packages (ZXing is already in from Phase 0).

---

## 3. Migrations (`database/migrations/2026_10_26_*_p1_*`)

| # | Migration | Tables / objects |
|---|---|---|
| 000100 | `create_catalog_lookup_tables` | `categories` (self-parent tree), `brands`, `uoms` |
| 000200 | `create_branch_locations_table` | `branch_locations` (shelves per branch) |
| 000300 | `create_suppliers_table` | `suppliers` (finance columns present, used from Phase 4) |
| 000400 | `create_products_table` | `products` + trigram index on `sku`, GIN on `name` (Postgres) |
| 000500 | `create_product_barcodes_table` | `product_barcodes` |
| 000600 | `create_branch_product_settings_table` | `branch_product_settings` (min/max/reorder per branch) |
| 000700 | `create_expiry_levels_table` | `expiry_levels` + partial unique index "exactly one expired level" |
| 000800 | `create_stock_units_table` | `stock_units` (serial; global unique serial; CHECK on status/location_type) |
| 000900 | `create_stock_batches_table` | `stock_batches` (lot buckets; `CHECK quantity >= 0`; NULLS NOT DISTINCT unique) |
| 001000 | `create_stock_movements_table` | `stock_movements` + **append-only trigger** |
| 001100 | `create_label_templates_table` | `label_templates` |
| 001200 | `create_unrecognized_scans_table` | `unrecognized_scans` |
| 001300 | `create_data_import_tables` | `data_imports`, `data_import_rows` |
| 001400 | `create_stock_views` | `v_stock_on_hand`, `v_low_stock` (Postgres + a SQLite version for the preview) |

Columns that point to later-phase tables (`stock_units.kit_id`, `customer_id`, `purchase_item_id`, `cost_fx_snapshot_id`, same on batches/movements, `product_barcodes.supplier_id`) are **added by the phase that creates those tables**, as DATABASE.md says.

---

## 4. Code

### Enums (`app/Enums`)
`TrackingType` (serial, lot) · `StockStatus` (10 statuses — label, color, icon) · `LocationType` · `MovementType` (all 20 types; Phase 1 uses `opening_balance`, `import`, `status_override`, `cost_adjustment`) · `BarcodeSymbology` · `ScanContext` · `ImportType` · `ImportStatus` · `ImportRowAction`.

### Models (`app/Models`)
`Category`, `Brand`, `Uom`, `BranchLocation`, `Supplier`, `Product`, `ProductBarcode`, `BranchProductSetting`, `ExpiryLevel`, `StockUnit`, `StockBatch`, `StockMovement` (no update/delete), `LabelTemplate`, `UnrecognizedScan`, `DataImport`, `DataImportRow`. Translatable fields use `HasCleanTranslations`; all business models use `TracksAuthor` + `LogsActivity` (serial/lot go into the log's searchable reference code).

### Services (`app/Services`) — all business logic lives here
| Service | Does |
|---|---|
| `Catalog/ProductService` | create/update products, barcodes, per-branch min stock; SKU/GTIN uniqueness |
| `Stock/StockMovementService` | **the only writer of stock.** `receiveOpening()`, `correct()`, `overrideStatus()`; every call = `DB::transaction` + `lockForUpdate` + ledger row(s) with one `movement_group`. Blocks expired units unless `stock.override_expired` + reason. Later phases add send/receive/use/sell methods here. |
| `Stock/StockQuery` | branch-scoped on-hand queries (through `BranchScopeResolver`), stock value per currency |
| `Stock/FefoPicker` | "soonest-expiring valid unit/lot first" for a product + branch; skips blocked expiry levels |
| `Stock/SerialHistory` | timeline for one unit (movements + activity log, newest first) |
| `Stock/RecallSearch` | lot → every unit/batch with that lot, where it is now (and from Phase 3: which surgery/patient) |
| `Barcode/ScanResolver` | raw scan → result (`unit`, `batch`, `product`, `unknown`) through a **chain of parsers**; unknown scans are saved to `unrecognized_scans` |
| `Barcode/Parsers/*` | `Gs1Parser` (GS1 DataMatrix / GS1-128: `]d2`, `]C1`, `]Q3` prefixes, GS `\x1D`, parenthesised form; AIs 01, 10, 11, 17, 21, + 240/241/30/37 kept as extras), `SerialParser` (exact serial), `ProductCodeParser` (SKU, GTIN-8/12/13/14 with check digit, `product_barcodes`), `InternalLabelParser` (our own labels). New vendor formats = one new class in `config('orthotrack.scan_parsers')`. |
| `Labels/LabelRenderer` | product / unit / lot labels (and kits / instruments in Phase 3) from a `label_templates` layout → SVG barcodes in a page sized to the label |
| `Expiry/ExpiryLevelService` | save the whole level set; validates no gap, no overlap, last level open-ended, "Expired" fixed at ≤ 0; computes black/white text color from the chosen color |
| `Expiry/ExpiryClassifier` | days left → level (cached level set) |
| `Expiry/ExpiryTransitionNotifier` | daily: finds units/lots that entered a new level, updates `last_expiry_level_id`, sends **one digest per branch** (not one per unit) |
| `Stock/LowStockDetector` | daily + after each movement: product × branch below minimum → digest notification |
| `Imports/ImportPipeline` | upload → validate → preview → confirm → queued apply. `ProductRowHandler`, `StockRowHandler`, `MinStockRowHandler`; `DateParser` (Excel serial dates + `YYYY-MM-DD` text), `DiffBuilder` (old → new per field), `TemplateBuilder` (example row + instructions sheet in en/ar/ckb), `ErrorFileWriter` |
| `Branches/Checks/StockCheck` | added to `branch_checks`: closing is blocked while the branch holds any stock (shows counts per status) |

### Jobs / scheduler
`ValidateImportJob`, `ApplyImportChunkJob` (queue `imports`, 500 rows per chunk, progress in `processed_rows`) · `ExpirySweepJob` and `LowStockSweepJob` daily at 06:00 Asia/Baghdad.

### Notifications
`ExpiryLevelEntered` and `LowStockDigest` → database + web push, respecting `notification_preferences`, sent to users who have `stock.view` for that branch.

### Permissions (registry already has most of them)
Uses existing `products.*`, `stock.*`, `suppliers.*`, `settings.expiry_levels`, `settings.lists`. **Adds:** `stock.receive` (enter opening stock, scoped), `stock.correct` (fix lot/expiry/cost with reason, scoped), `labels.templates` (edit label layouts). Role templates updated: Branch Staff gets `stock.view` + `stock.receive` + `products.print_labels` for own branch; Doctor gets `stock.view`; Accountant gets `stock.view_cost` + exports.

---

## 5. Screens

### Admin panel (`/admin`, Filament)
| Group | Screen | Notes |
|---|---|---|
| Catalog | **Products** | list with search by SKU/GTIN/name/barcode, filters (category, brand, tracking, active); form with EN/AR/KU names, photo, prices + currency, min stock; tabs: **Barcodes**, **Min stock per branch**, **Stock by branch** (FEFO order); actions: **Print labels**, Export |
| Catalog | Categories (tree) · Brands · Units · **Suppliers** | lookups; translatable where the schema says so |
| Stock | **Stock units (serial)** | branch-scoped; columns: serial, product, lot, expiry chip (level color + label + days left), status, branch, shelf; filters by expiry level / status / branch; unit page = **serial history timeline** |
| Stock | **Lots** | lot buckets with quantities, same expiry chip |
| Stock | **Receive stock** | scan field at the top (GS1 fills everything) → add rows → confirm → ledger; works with a USB/Bluetooth scanner |
| Stock | **Recall search** | type/scan a lot → every unit and batch, where it is now, printable list |
| Stock | **Movements** | read-only ledger, filter by product/serial/type/branch/date |
| Stock | **Low stock** | product × branch below minimum, edit min inline |
| Stock | **Import / export** | wizard: pick type → download template → upload → preview (counts new/updated/unchanged/errors, red rows with reasons, old → new per field) → confirm → progress bar → error file; import history with original file |
| Settings | **Expiry levels** | editable list with color picker and labels in 3 languages, live gap/overlap check, preview chips |
| Settings | **Label templates** | size (mm), symbology, fields; live preview |
| Settings | Unrecognized scans | raw text + hex, to add new formats when the client's real boxes arrive |
| Branches | Branch page | adds **Shelves** tab and the stock blocker on close |
| Dashboard | widgets | expiry counts per level per branch (his colors), low-stock count |

### Ops app (`/app`, phone)
Bottom nav becomes **Home · Stock · Scan · Alerts · Me** (5 items, Scan in the middle).

| Screen | Notes |
|---|---|
| **Scan** (upgraded) | every scan is resolved: unit card (product, serial, lot, expiry chip with days left, status, branch, shelf) · product card (stock here, FEFO suggestion) · "not found" with "report this code" (saved for the admin). Big result card, beep/vibrate already wired. |
| **Stock** | branch-scoped list as cards: search or scan, filter by expiry level, shows qty per product, tap → product page |
| **Product** | stock in my branch(es) by lot/serial, FEFO first, min level |
| **Unit** | serial history timeline |
| **Recall** | lot search (only with `stock.recall`) |
| **Receive stock** | phone version of opening-stock entry by scanning (only with `stock.receive`) |
| Home | adds counts: expiring soon in my branch, low stock |

All screens built with the `/ui-ux-pro-max` rules in `docs/design/DESIGN-SYSTEM.md`: expiry chips always color + label + days, codes in `<x-code>` (Western, LTR), dates `YYYY-MM-DD`, 48px touch targets, cards on mobile, empty/loading states.

### Routes
Ops: `/app/stock`, `/app/stock/products/{product}`, `/app/stock/units/{unit}`, `/app/stock/receive`, `/app/recall` (Livewire, `ops.stock.*`). Web: `/labels/{template}/print` (signed, permission-checked), `/imports/{import}/original` and `/errors` downloads. Admin routes come from Filament resources/pages.

---

## 6. Seeders
`ExpiryLevelSeeder` (the 5 default levels — runs in production too), `UomSeeder` (pc, box, pack), `LabelTemplateSeeder` (50×25 mm Code128, 40×30 mm DataMatrix). Demo only: `DemoCatalogSeeder` — ~15 products (knee/hip implants serial-tracked in several sizes, screws/plates/cement lot-tracked), 3 suppliers, stock in DHK/EBL/BGW spread over all expiry levels, a few items below minimum, some shelves.

---

## 7. Tests (Pest)
* **GS1 parser** — shared vectors file (`tests/fixtures/gs1-vectors.json`): parentheses form, GS separators, symbology prefixes, AI 17 with day `00`, variable-length AIs at the end, invalid check digit.
* **Expiry levels** — gaps, overlaps, open end, expired level can't be deleted, text color contrast.
* **Stock ledger** — every change writes a movement; quantity never negative; same serial can't be received twice (concurrent attempts); expired unit blocked without override; override needs reason + permission; ledger rows can't be updated/deleted.
* **FEFO** — soonest valid expiry first, blocked levels skipped.
* **Scan resolver** — GS1 → existing unit; GS1 for a new unit → prefilled receive; plain SKU; extra barcode; unknown → saved in `unrecognized_scans`.
* **Excel import** — preview counts and old → new diff; empty cells don't overwrite; can't change branch/status; serial-tracked qty must be 1; Excel numeric dates and text dates; expired warning; Arabic/Kurdish text intact; error file; original file kept; import logged.
* **Branch close** blocked by stock; **branch scope** on stock lists and unit pages (ops + admin agree); **recall search**; **low stock** view; label page renders; smoke tests for every new screen in all 3 languages.

After building: `migrate:fresh --seed`, full test suite, Larastan, Pint, browser run-through of admin + ops (ar/ckb on a phone viewport), CHANGELOG + README update, then a commit.

---

## 8. Not in Phase 1
Transfers, stock requests, returns, write-offs, counts (Phase 2) · kits, surgeries, hospitals (Phase 3) · purchases, landed costs, exchange rates (Phase 4) · PDFs of documents and reports (Phase 5).
