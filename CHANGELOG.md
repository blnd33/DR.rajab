# Changelog

All notable changes per build phase.

## [Client updates — two companies, separate data] — 2026-11-19

### Changed
- **Al-Fadhaa' Bureau is now a company of its own** (replaces the zero-only Main page of the previous entry): same system, **separate data**. Each company has its own database — staff and logins, stations, stock, money, documents, settings — and its own session, cache, uploaded files and backups (`App\Support\CompanyContext`). The company is chosen when the request starts: the top-bar switch (also on the sign-in page) sets a cookie; commands and cron use `ORTHOTRACK_COMPANY=al_fadhaa`. Switching company opens the other company's own system (sign in there; automatic on the test site).
- **One switch button, next to the company name** (and on the sign-in page): in KurdishMedicus it says *Al-Fadhaa' Bureau*; in Al-Fadhaa' Bureau it shows KurdishMedicus' own name (its Settings → company name).
- **Each company sees only its own governorates** on the Main page overview: KurdishMedicus all 19, Al-Fadhaa' Bureau **Baghdad, Karbala, Babil** (only "Total in Iraq"; the Iraq-and-Kurdistan total only when a company works in both).
- - A company is switched on by giving it a database: `ORTHOTRACK_AL_FADHAA_DATABASE` (same server and login as `DB_*`). The switch only shows when it has one.

### Added
- **`orthotrack:setup-company`**: tables, base data, company name and stations of the current company — Al-Fadhaa' Bureau: **Baghdad, Karbala, Babil** (governorates set, so the Main page shows them green). Safe to run again.
- **`orthotrack:migrate-all`**: database changes for every company.
- **Tests**: a company only with a database; its own database, session, cache, files, backups and permission cache; setup with stations; data never mixes (second database as SQLite); the command-line company; the switch.
- docs/TEST-SITE.md: *Two companies* — server steps and the second cron line.

## [Client updates — Al-Fadhaa' Bureau] — 2026-11-19

### Added
- **Company switch in the top bar** (where the top-bar search was): **KurdishMedicus | Al-Fadhaa' Bureau** (Arabic *مكتب الفضاء*, Kurdish *نووسینگەی ئەلفەزاء*); the chosen one is highlighted. Clicking a company opens its **Main page**.
- **Al-Fadhaa' Bureau's Main page** is the same page as KurdishMedicus' — specialty and overview buttons, search, stock, money, invoices, waiting work, surgeries, expiry, charts, most used implants — for that company's own stations. It has none yet, so **everything shows zero** (*This company has no stations yet…*). The system-wide boxes (alerts, users, Daily Business Report) stay on KurdishMedicus' page only; the station picker is hidden there.
  - How: all stations belong to KurdishMedicus; in another company every branch-based permission finds no station (`AdminCompany`, `BranchScopeResolver::focusNoBranches`, `FocusAdminBranch`), so screens stay but show nothing.
- **Tests**: switch and back, zero stock and overview, unknown company, guests.

## [Client updates — Arthroplasty icon] — 2026-11-19

### Changed
- **Arthroplasty icon**: now the hip implant alone — ball in its cup, neck with collar, tapered stem (`resources/svg/hip-prosthesis.svg`).

## [Client updates — round flags, one search, Daily Business Report, specialty icons] — 2026-11-19

### Changed
- **Language flags are round** (the middle of each flag in a circle) in the top bar, the language menu and the sign-in page.
- **One search**: the top-bar search is removed; *Search the whole system* on the Main page stays.
- **Morning report → Daily Business Report** everywhere (menu, page, phone app, profile, settings, notification, permission) — Arabic *تقرير الأعمال اليومي*, Kurdish *ڕاپۆرتی ڕۆژانەی کار*.
- **Specialty icons** (Main page buttons and overview): new line drawings — Trauma a broken bone, Spine the vertebrae, Sport medicine the knee, Arthroplasty a hip prosthesis (`resources/svg`, icon set `ot-`), shown larger.

## [Test site — sample data from the supplier workbook] — 2026-11-19

### Added
- **`php artisan orthotrack:sample-data <workbook.xlsx>`** (`SampleDataService`), for the test site, from the doctor's own supplier workbook (e.g. *IT_MIKRON_Trauma_items*): exchange rates if none (1 USD = 1,460 IQD, 1 EUR = 1.08 USD); five stations — Duhok, Erbil, Sulaymaniyah, Baghdad, Basra, each with its governorate; the whole workbook through the normal supplier-catalog import; **60 products in stock** (`--products=`) spread over the stations, one lot each (`SMP-<station>-001`…), received through the stock ledger at their test cost; then the **test prices** (1, 2, 3… USD by SKU, selling + 20 %). Runs again without adding anything twice; refused on a real site (docs/TEST-SITE.md).
- **Tests**: stations, import, stock and prices; a second run; refused on a real site; the command.

## [Test site — open without signing in] — 2026-11-19

### Added
- **`ORTHOTRACK_AUTO_LOGIN`** (test site only): with `ORTHOTRACK_TEST_SITE=true`, every visitor is signed in automatically — as the first active super admin (`true`) or as a named account (a username). No login page, no two-factor step. The test-site strip says *Open without signing in.* Ignored on any site that is not a test site, so the real site always asks to sign in; remove the line to ask again (`AutoLogin`, `AutoLoginOnTestSite`, docs/TEST-SITE.md).
- **Tests**: admin panel and phone app open without signing in, the named account, never on a real site, off / unknown account.

## [Test site — test prices] — 2026-11-19

### Added
- **`php artisan prices:test-fill`** (`TestPricingService`), asked for the test site: every product that has stock, in **SKU order**, gets a cost of **1, 2, 3… USD**, and a **selling price of cost + 20 %** (1.20, 2.40, 3.60…). The **pieces already in stock** get the same cost through the normal stock correction (reason *"Test prices…"*, one ledger row each), so Stock value, Sales and profit and the Main page show these numbers. Products without stock keep their price.
  - `--dry-run` shows the list first; it asks before changing anything (`--force` to skip). Options `--start`, `--step`, `--markup`, `--user`.
  - A piece with landed costs keeps them (its base cost is lowered so the total is the test cost); one whose landed costs are in another currency is left as it is and counted.
- **Tests**: order and prices, products and pieces (serial and lot) with the ledger, start / step / markup, permissions, the command with its preview.

## [Client updates — company documents] — 2026-11-19

### Added
- **Company documents in the side menu**: four new sections, each folder an entry inside it:
  - **Catalogues**: Lima, Mikron, Covision.
  - **Official company documents**: Company registration.
  - **Supplier company documents**: Contractual, Declarations, PL / invoice / Dual use / COO, Others.
  - **Shipment documents**: AWB, Customs clearance, Income tax, Internal transport, Other.
  - In the existing **Finance** menu: Legal / Regulatory department, Internal transport invoices / PL.
- **A folder** (`DocumentFolderPage`, `/admin/documents?folder=…`): upload a document (title, reference number, document date, optional expiry date, notes, one or more files — PDF, Word, Excel, photos or zip, up to 50 MB each), open / download its files, search, add files later, edit the details. Nothing is deleted: a document is **archived with a reason** (Archived filter) and can be brought back. Expiry dates show red when passed, amber within 30 days.
- **Administration → Document folders**: the super admin adds a folder (e.g. a new catalogue supplier) in any section, renames it (three languages), reorders or hides it; the menu follows at once (`DocumentNavigation`).
- Permissions *View company documents*, *Upload and archive company documents*, *Add and rename document folders* (`documents.*`), given to Super Admin and Manager; files are private (opened only by people who may view documents).
- Tables `document_folders`, `company_documents`; attachment kind `document` (`2026_11_19_000300_add_company_documents`, which also creates the folders above).
- **Tests**: the folders and their sections, upload / edit / archive / bring back, wrong files and dates, permissions and private files, the menu (new and hidden folders), upload from the page, every language.

## [Client updates — Main page buttons, overview by region, search] — 2026-11-19

### Added
- **Big buttons in the centre of the Main page** (`OverviewLauncher`, shown first):
  - Row 1: **Arthroplasty · Trauma · Spine · Sport medicine** and a **search over the whole system**.
  - Row 2: **Stations · Stock · Value · Sales · Profit**.
- **Overview page** (`/admin/overview`, `OverviewPage` + `OverviewService`):
  - A row-2 button shows **Total in Iraq** (the 15 governorates outside the Kurdistan Region), **Total in Kurdistan** (Duhok, Erbil, Sulaymaniyah, Halabja) and **Iraq and Kurdistan**, then the **19 governorates** — green where there is something, red where there is nothing. A governorate → the four specialties with their numbers → product by product (SKU, product, station).
  - A specialty button shows every button's numbers for that specialty per region, the governorates, and a governorate product by product.
  - Stock = pieces on hand; Value = true cost in USD; Sales and Profit = like the Sales and profit report, in USD, with a period (Today / This month / This year / All time / dates; this month by default). Stations count the stations there (with a specialty: those holding its stock).
  - Each button only for people who may see it, and only their stations: stock & stations `stock.view`, value `stock.view_cost`, sales `reports.view`, profit `reports.profit`.
- **Search the whole system** (`SystemSearch`): codes (serial, lot, barcode, SKU, instrument set, document numbers), every admin list the person may open, and product / hospital / station names in all three languages.
- **Station governorate** (Administration → Stations) and **category specialty** (Catalog → Categories; a product takes the nearest one up its category tree). Existing stations and categories are filled with a best guess from their names; supplier imports set it (Lima → Arthroplasty, Mikron → Trauma). Stations without a governorate are listed on the overview so they can be fixed (`2026_11_19_000200_add_governorates_and_specialties`).
- **Tests**: regions and guesses, stock / value / sales / profit per region, governorate and specialty, permissions and stations, walking through the screens, every language, the search.

## [Client updates — company agents and drivers] — 2026-11-19

### Added
- **Company agents** in the admin side menu (permission *Manage company agents and drivers*, `agents.manage`: Super Admin and Manager). Kept by the super admin; none of them signs in; never deleted (made inactive):
  - **Distributors**: name, phone, city, notes.
  - **Direct sellers**: city, persons, value (amount + currency), stock.
  - **Representatives**: CV, salary, fee per mission.
  - **Partners**: city, stocks (share %), value, profit per month and per year (empty year = month × 12).
  - **Technicians**: the existing technicians moved here from Clinical, now with CV, position and salary (shown only with `agents.manage`). Still picked on surgeries; their history stays.
  - **Employees**: CV, position, salary, and an optional link to their system account (one account per employee, never a technician).
  - Money is an amount with IQD, USD or EUR. CVs (PDF, Word or photo, up to 10 MB) are private attachments; a new CV is kept next to the old ones.
- **Drivers** (Company agents → Drivers): driver name and number, car type, our car or the driver's own, and the **car plate**:
  - English plate: 2-digit code, letter, number → `24 A 3003`.
  - Arabic plate: number and *Iraq* or the governorate (all 19) → `12345 دهوك`.
  - The form checks the format and shows how the plate reads; Arabic-Indic digits are stored as Western digits (`App\Support\Plate`).
  - **Trips** per driver: date of arrival, date received (can be added later with *Mark received*), and what we paid in IQD, USD or EUR, with the totals per currency. Recorded only — no cash box moves. A wrong trip is voided with a reason (the amount is never edited).
- Tables `company_agents`, `drivers`, `driver_trips`; attachment kind `cv` (`2026_11_19_000100_add_company_agents`). Demo data: one or two of each agent, two drivers with trips.
- **Tests**: what each kind keeps, money and share rules, CV upload and privacy, employee account links, technician profiles, both plate kinds and the wrong ones, trips (totals, dates, void), the screens in every language.

## [Client updates — new names, language flags, KM badge] — 2026-11-19

Names changed everywhere on screen, in English, Arabic and Kurdish (menus, pages, forms, messages, reports, notifications, Excel help, permission names). Only the words people see changed — database tables, URLs and codes stay the same.

### Changed
- **Dashboard → Main page** (AR: الصفحة الرئيسية, KU: پەڕەی سەرەکی).
- **Branches → Stations** (AR: المحطات — feminine, so the sentences around it were adjusted; KU: وێستگەکان). The Excel import column is now *Station*; files with the old *Branch* column still import.
- **Kits → Instrument sets** (AR: أطقم الأدوات, KU: سێتی ئامێر), so they are not confused with the single **instruments** inside them, which keep their name. Kit types → Instrument set types, Kits report → Instrument sets report.
- **Morning summary → Morning report** (AR: التقرير الصباحي, KU: ڕاپۆرتی بەیانیان).
- **Language switcher with flags**: English 🇺🇸 United States, العربية Iraq, کوردی Kurdistan. In the admin and phone top bars it is now a small menu showing the current flag; the sign-in page shows all three with their flags (`<x-flag>`, `config('locales.supported.*.flag')`).
- **Profile badge KM** (KurdishMedicus): the admin top-bar picture and the *Me* button in the phone app (`KmAvatarProvider`, `public/icons/km-avatar.svg`); no more initials from an outside avatar service.

## [Client updates — branch switcher, who owes us, product categories] — 2026-11-15

### Added
- **Branch switcher in the admin top bar** (*All branches* or one branch, among the person's own). Everything branch-based follows it: lists, dashboard cards, reports, graphs, pickers and actions (`BranchScopeResolver::focus`, only for the person who chose it; permissions that are not about a branch are untouched). Users and Technicians list only the chosen branch's accounts; doctor and technician pickers show only the people who work in the branch (admin: the branch on the form; phone: the current branch).
- **Manager** role (built in, every permission). Give the manager's account *All branches* and it sees every branch, report and graph.
- **Technicians have branches** (form, list, demo data).
- **Dashboard → Invoices: paid and not paid**: received this month, still owed to us (USD at today's rate, each currency underneath), how many invoices wait and the oldest; **Who owes us** — each unpaid invoice with who owes it, what it is for (items or surgery), the doctor (lead surgeon, or the seller of a sale), the amount, days waiting and *Mark as paid*.
- **Invoices report** (Reports): each invoice, or added up by who owes, by doctor or paid / not paid — total, paid, still owed; print and Excel like the other reports.
- **Choose surgery products by category** (admin and phone): Category → Product (only products with stock in the branch; a parent category includes its children) → Serial for a serial item or Quantity for a lot item. Scanning still works. The phone list is part of the page, so it works offline.
- **Tests**: branch narrowing and the switch, pickers by branch, Users by branch, the Manager role, the report groups, the dashboard widgets, the category picker.

## [Client updates — language, expenses, technicians, invoices] — 2026-11-15

Changes asked by the client: language from every page, currency and editing on expenses, technicians instead of assistants, and invoices for everything with a paid / not paid switch.

### Added
- **Language switcher in the top bar** of the admin panel and the phone app (still on the sign-in page too). The choice is saved on the account, so both apps follow it.
- **Expense currency**: the expense form (admin and phone) asks the currency first (USD / EUR / IQD) and lists only the boxes holding it; the service refuses a box in another currency.
- **Edit an expense** (`expenses.update`, Accountant): one waiting for approval is changed in place and its approval request follows; a paid one needs a reason and is corrected in its box — the old payment is reversed (new ledger row) and the corrected amount paid again.
- **Technicians** (*Clinical → Technicians*, `technicians.manage`): one account per technician that never signs in and has no permissions (role `technician` kept empty, panels refused, `hasPermissionTo` always false). The surgery form (admin and phone) picks **technicians** instead of assistants; older surgeries keep their assistants. Each technician's page lists their surgeries, this month's count and the fees per currency.
- **Graphs → Technicians**: surgeries by technician, technician work per month (one line each), surgery money by technician (in the chosen currency), technician work by surgery type.
- **Surgery fee**: optional amount + currency on the surgery form; a completed surgery with a fee gets an **unpaid invoice**. *Make an invoice* on a completed surgery without one. Cancelling a surgery voids its unpaid invoice (a paid one must be marked not paid first).
- **Invoices** (*Finance → Invoices*, the former *Sales*): sales and surgery invoices together, tabs *Not paid / Paid / All* and the unpaid total per currency. **Mark as paid** (`invoices.mark_paid`) takes the money into a box of the invoice's currency — only then it counts as received; **Mark as not paid** (with a reason) reverses it. **Edit** (`invoices.update`) while unpaid: buyer, notes, due date, a surgery's fee and currency, a sale's line amounts and discount (items stay; void to change them). Credit invoices keep their customer-payment flow.
- New sale (admin and phone): an **Already paid** tick; without it the sale is saved on an unpaid *Pay later* invoice (stock leaves, the money waits).
- Demo data: two technicians on the demo surgeries, one surgery invoice paid and one waiting.
- **Tests**: expense currency and editing, technicians (no sign-in, no permissions, on surgeries, graphs), invoices (surgery fee, mark paid / not paid, edit, pay-later sale, void after re-payment, cancel rules), the counter sale with and without the tick.

### Changed
- The **patient is optional** on a surgery; a patient file is only made when a name was typed (database check relaxed to hospital + surgery type).
- Voiding a sale reverses only receipts still standing (one marked paid, then not paid, is already reversed) and resets a surgery's billing.
- Sales report counts and the *Sales by currency* graph read sale invoices only.
- `Pg::check` runs unprepared so PDO does not read the jsonb `?` operator as a placeholder (fresh migrations failed on PHP 8.3 + PostgreSQL).

## [Phase 6 — Quick answers] — 2026-11-11

A chat-style screen with **ready-made questions** and a box to **type or scan any code** ([plan](docs/phases/PHASE-6-PLAN.md) §3). No AI: answers come straight from the facts and the existing lookups, so they are exact and cost nothing to run.

### Added
- **Questions** (`QuickQuestions`): *What happened today / yesterday?* (the morning summary's content for that day), *What expires in the next 30 / 60 / 90 days?*, *What is below minimum?*, *Which kits are out / overdue?*, *What is missing from returned kits?*, *Surgeries by hospital / by doctor*, *Surgeries not finished*, *Sales by hospital*, *Payments received*, *Unpaid hospital balances*, *Supplier invoices due*, *Cash in the cash boxes now*, *Expenses by category*, *Profit*, *Open issues*, *Approvals waiting for me*, *Transfers waiting to be received*; plus *How many of an item…?*, *Where is a serial number?* and *Is a kit complete?*, which ask for a code. Each person sees only the questions their permissions answer. Choices: period (today / yesterday / this week from Saturday / this month / dates), branch, currency.
- **Type or scan anything** (`CodeLookup`): a serial (status, where, lot, expiry, last movements), a SKU / GTIN / GS1 label / extra barcode (how many per branch, first expiry), a lot, a kit (complete or what is missing), an instrument, a document number (`TRF-…`, `SRG-…`, `INV-…`, `PUR-…`, `RCP-…`… — through the admin screen's own permission and branch rules) and a patient file number (only the patients the person may see; the opening is logged). Typed codes nobody knows are not saved as unknown scans.
- **Words** (`KeywordMatcher`): a few words in English, Arabic or Kurdish (*overdue kits*, *انتهاء الصلاحية*, *خەرجی*) suggest the closest questions; Arabic and Kurdish spellings are folded together. Otherwise: *"I can answer these:"* and the list.
- **Screens**: admin *Insight → Quick answers*; on the phone *Ask* (`/app/ask`, with the camera and scanner gun) and a card on *Home*. Nothing typed is stored.
- New fact *Expires soon* (items expiring within N days, by product); *Kits out* can show only the overdue ones, *Surgeries* can count by doctor.
- **Tests**: questions per role, a period and a branch, the Saturday week, expiry windows, today's facts, serial / product / lot / kit / document / patient lookups and who sees them, unknown codes, words in three languages, both screens, the permission.

### Changed
- The morning summary and Quick answers share one answer card (admin and phone); cards for a single thing say *Open* instead of *Open the full list*, and long values wrap.

## [Phase 6 — morning summary] — 2026-11-11

Each person who may receive it gets **yesterday's numbers and what needs attention today**, once a day at their own time, in the bell and as a phone notification ([plan](docs/phases/PHASE-6-PLAN.md) §2).

### Added
- **Morning summary**: built from the facts (five sections — *At a glance*, *Money*, *Kits*, *Expiry and stock*, *Needs attention*); empty facts are left out; written in the person's own language; only what the person may see, for all their branches or the one they chose.
- **Sending**: `summary:send` runs every 15 minutes and sends to everyone whose time has come and who has not had today's summary (`--user=` sends one person's now). Every sent summary is kept (`morning_summaries`, one per person per day). The notification line has counts only (*Yesterday: 1 kit overdue · 3 open issues*), never patient or hospital names, because it shows on a locked phone. The system-health page watches the job.
- **Screens**: admin *Insight → Morning summary* (any earlier morning by date, as sent or built now; *Send it to me now*; a note when it was written in another language) and a dashboard card with today's line; on the phone *Morning summary* (`/app/summary`) and a card on *Home*.
- **Settings**: each person in their profile (admin) or *Me* (phone) — on/off, time, branch, sections, not on Fridays. Defaults for everyone in *Settings → Morning summary* (07:30, all sections, Fridays included).
- **Tests**: defaults and own settings, validation, due once a day at the person's time, skipped Fridays, the permission, sent once and kept, counts-only notification, chosen sections, the person's language, the admin and phone pages, both settings forms, the command.

## [Phase 6 — facts engine] — 2026-11-10

The shared building blocks of the coming **morning summary** and **Quick answers** ([plan](docs/phases/PHASE-6-PLAN.md) §1). No screens yet.

### Added
- **Facts** (`app/Services/Insight/`): 19 small answers, each for one person, a period, a branch and a display currency — *Surgeries* (by hospital), *Kits sent and returned*, *Transfers sent and received*, *Stock received*, *Sales* (by hospital), *Payments received*, *Profit*, *Expenses* (by category), *Unpaid hospital balances*, *Supplier invoices due* (overdue or within 7 days), *Cash in the boxes*, *Kits out at hospitals* (overdue first, days late), *Missing after a return*, *Expiry* (per expiry level), *Below minimum*, *Open issues*, *Waiting for your approval*, *Transfers waiting to be received*, *Surgeries not finished*. Each checks its own permission and branches (and the chosen branch), reuses the reports' and dashboard's numbers, converts money with each row's own exchange rates, and returns a headline, up to five lines, the raw numbers and a link to the full screen. `FactRegistry::FACTS` is the list; adding a question = one class.
- **Permissions** `summary.receive` (*Receive the morning summary* — Accountant by default) and `assistant.use` (*Use Quick answers* — Accountant, Doctor, Cashier, Branch staff); Super Admin has both. A migration gives them to the built-in roles of an existing database (the test site gets them on update).
- **Tests**: every fact in three languages, who sees which fact (profit only with `reports.profit`), branch scope and the chosen branch, sales in USD and IQD with each sale's own rate, payments, hospital balances, a sale after midnight on the right day, kits out with days late.

### Fixed
- **Report and graph periods ran 03:00 → 03:00.** Times are stored in UTC but the period was sent to the database in Baghdad time, so e.g. a sale at 01:00 counted on the previous day in *Sales and profit*, *Cash*, *Kits*, *Stock movements* and the graphs. Timestamps are now compared in UTC (`ReportContext::bounds()`).

### Changed
- Which approvals a person sees is decided in `ApprovalService` (was in the admin screen), so the approvals list and the facts share one rule.

## [Mikron trauma workbook import] — 2026-11-10

### Added
- **Supplier catalog import reads the Mikron trauma workbook** as the doctor sends it (*Supplier catalog (Lima / Enovis or Mikron workbook)*). The import looks at the sheet names and picks the Lima or the Mikron reader by itself; the checks, preview and *Import N rows* step are the same.
  - *ALL PLATES*, *CANN&HER&HEAD*, *ALL NAILS*: every item becomes a product (brand Mikron, lot-tracked implant) under the new area **Trauma**, grouped by the sheet's heading rows (*Cranial Plates*, *SCREWS for SMALL FRAGMENT Plates*, *HUMERUS Nail*…). The EAN code becomes the product's GTIN, so the box scans by itself. Size from the description: `3.5 × 35 mm`, `9 holes · Right`. Code ranges such as `MX3001002506-40` (*Same as for MINI Plates*) point at items listed elsewhere and are skipped.
  - *ALL Instrument Sets Summary*: each set's group (*Plate*, *Nail*…) and usage from the colour key beside the table; red *Not ordered / Not available / Factory don't produce it* = *Not used* (kept usable, sorts last).
  - *ALL Instrument Sets Details*: the instruments of each set, with their quantities (size variants such as reamers `MTFN25-10.0` are their own instruments). The three *Miscellaneous* entries (two trays and a plate bender) are already part of the Small Fragment set, so they are instruments, not sets.
  - Columns are found by their header text (the sheets differ), typos fixed (*ARTHRODRSIS* → *ARTHRODESIS*).
  - The doctor's file of 09.11.2026: **2,004 products, 555 instruments, 19 sets, no errors**; checked in 2 s, written in 10 s; importing it again changes nothing.
- **Tests**: groups, barcodes, sizes, skipped ranges, typos, set usage from the key, set contents with quantities and dashed codes, a tray that stays an instrument, the full import under Trauma (Arabic area name), re-import unchanged, a recoloured set updated, an unknown workbook refused with a message naming both formats.

## [Set price for many products] — 2026-11-10

### Added
- **Set price** on the products list (*Bulk actions*, `products.manage`): tick the products — or *Select all* to take the whole filtered list, e.g. the 1,828 imported Lima implants — and change the **selling price, the purchase price or both** in one step:
  - *One price for all*: an amount in USD, EUR or IQD (Arabic / Kurdish digits accepted); optionally *only products that have no price yet*.
  - *Up or down by %*: e.g. `10` = 10 % more, `-5` = 5 % less; products without that price are skipped and the currency stays.
  The message says how many prices changed and how many products were left as they were (same price already, or skipped). Every changed product is saved on its own, so its history shows old → new; amounts use exact decimal maths (4 decimals). Replaces the server command used on the test site on 2026-11-10.
- **Tests**: one price with history, unchanged when the same, only empty prices, both prices, percentage up / down keeping the currency, wrong input refused (no price chosen, negative, unknown currency, not a number, 0 % or −100 %), people without `products.manage` refused, both modes from the list.

## [Kits: fill from its list, instrument and kit labels] — 2026-11-10

### Added
- **Fill from its list** on a kit (admin *Kit* page and phone app *Prepare / refill*, `kits.prepare`): every instrument missing from the kit type's list goes into the kit in one step — free instruments of that type on the kit's branch shelf first, then new instruments registered with their own code (`INS-<branch>-NNNN`, needs `instruments.manage`). Before filling, a table shows per instrument type how many are missing, how many come from the shelf and how many are registered new; implants are not added this way (the count still missing is shown — they are scanned in as before). One ledger row per instrument; refused while the kit is out or when nothing is missing. With the Lima sets imported as kit types, a box with 30–40 instruments is ready in one press instead of registering each instrument by hand.
- **Labels for instruments and kits**: the barcode holds the instrument's or kit's own code (what *Scan into kit* and the return check read); the text shows the name, the code and the catalogue code (`REF …`). *Print labels* on the instruments list (one or many), the instrument page, the kit's *Instruments* tab (*Print all labels*) and *Print kit label* on the kit page; after a fill, the message offers the labels of the new instruments (phone: *Print labels for the new instruments*). Only people who may see the instrument or kit can print it.
- **Tests**: the plan (shelf first, other branches not used, optional implants not counted), the fill with codes and ledger rows, filling only what is missing, refused for a kit that is out and without permission, labels and their access, the admin action and the phone screen.

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
