# Graphs, transfer photos and sales split by percentage — plan

> Status: **Built** (2026-11-08). Asked by the client on 2026-11-08; choices confirmed the same day:
> graphs in the **admin panel only**, transfer photos **both required**, sale percentages **from each piece's cost** (editable).

## 1. Graphs section (admin)

**What:** a new *Graphs* menu entry. One page lists every graph as a card (title, one-line description, chart kind, section). A **search box** filters by words in the title, description and keywords (all three languages), and section chips (Sales, Surgeries, Items & stock, Money, Transfers, Kits) narrow the list. Opening a card shows the graph with the same filters as the reports (period, branch, currency) and **a table under it with every value and its percentage** (screen readers, print, and the light-mode contrast relief the palette check asks for).

| Key | Graph | Kind | Source |
|---|---|---|---|
| G-1 | Graphs reuse the **reports' own numbers** (`Report::rows()` with a group) wherever a report exists — a graph never disagrees with its report, and branch scope / profit permission come for free. Graphs without a report (transfers, write-offs, catalogue usage, top implants) have their own small queries in `App\Services\Graphs`. |
| G-2 | **Who:** `reports.view` (any branch) — graphs are reports drawn. Graphs that show cost or profit also need `reports.profit`, like the report columns. No new role logic. |
| G-3 | **Colours** (dataviz method): the validated 8-slot categorical palette, light steps on the light panel and dark steps on the dark panel (validator: all checks pass in both; light-mode contrast WARN → the value table). Hues are assigned in fixed order by entity, never cycled; past 7 slices the rest fold into a grey **Other**. Pie / doughnut slices have a 2 px surface gap. Single-series bars and lines use slot 1. A small Chart.js plugin picks light or dark steps when the theme changes. |
| G-4 | **Forms:** share of a whole → doughnut; ranking → horizontal bar (top 10); change over time → line (12 months by default); counts per month → bar. Never two y-axes. |

**First set (≈30):**
* **Sales** — sales per month (revenue; cost too with profit permission), profit per month, sales by branch, sales by hospital / customer, sales by item (share of revenue), top sold items (quantity), sales by currency (USD / EUR / IQD).
* **Surgeries** — surgeries per month, by surgery type, by hospital, by lead surgeon, implants used per month, most used implants, implant cost per month.
* **Items & stock** — stock value by branch, by category, stock value history, items on hand by branch, items by expiry level, catalogue by usage level (Lima colours), catalogue by area (Hip / Knee / Shoulder…), stock movements by type, write-offs by reason.
* **Money** — expenses by category, by month, by branch, supplier debt by supplier.
* **Transfers** — transfers per month, transfers by route (from → to), transfer results (received, with differences, in transit, cancelled).
* **Kits** — kit check-outs per kit.

## 2. Transfer photos (sent / received)

| Key | Decision |
|---|---|
| T-1 | Two new attachment kinds: **Photo when sent** (`transfer_sent`) and **Photo when received** (`transfer_received`), on the existing private attachments (served only to people who may view the transfer). |
| T-2 | **Required.** `TransferService::send()` refuses without a *sent* photo; `receive()` refuses without a *received* photo. The photo can be added just before, or passed with the call. Closing a branch (transfer everything) asks for the photo too. |
| T-3 | Who may add: the *sent* photo — someone who may send from the source branch, while the transfer is a draft; the *received* photo — someone who may receive at the destination, while it is in transit. Images only, 10 MB max. Never deleted (append-only like the rest). |
| T-4 | Screens: phone transfer page — *Take photo* before *Send* and before *Confirm received*; admin transfer page — photo field in the Send and Receive dialogs; both show **Our photo (sent)** and **Their photo (received)** side by side. |

## 3. Sales split by percentage

The doctor's example: three pieces cost us 500 $ in total. Each piece's **cost share** is its percentage of the 500 $ (e.g. 50 % / 30 % / 20 %). We set one selling price for the three (e.g. 800 $) and the system splits it by the same percentages (400 / 240 / 160), so for each piece we see **how much we received and its percentage**.

| Key | Decision |
|---|---|
| S-1 | A sale is priced **per item** (as today) or **one price, split by cost %**. Invoice currency stays USD, EUR or IQD. |
| S-2 | Every sold line stores **cost share %** (its cost ÷ the invoice's cost, both converted to the invoice currency) and **price share %** (its amount ÷ the invoice total) — for both pricing modes, so the report shows percentages for every sale. |
| S-3 | Split mode: line amount = total × share, rounded to 4 decimals, the last line takes the rounding remainder so the lines add up exactly. Shares come from the cost and **can be edited** (they must add up to 100 %). Items without a cost get an equal share per piece. No extra discount in split mode (the total is the price). |
| S-4 | Screens: phone *New sale* and admin *New sales invoice* get the pricing choice, the total field and a live table (piece, cost %, amount). Cost amounts only for people with `stock.view_cost`; percentages for everyone selling. |
| S-5 | Report *Sales and profit*: new **Sale line** grouping (invoice · piece: cost, cost %, received, received %, profit) and **Cost %** / **Revenue %** columns (share of the period's total) in every grouping. The invoice page shows both percentages per line. The customer's printed invoice does not show cost percentages. |

## 4. Build list
* Migrations: attachment kinds; `sales_invoices.pricing`; `sales_invoice_items.cost_share`, `price_share`.
* Services: `Graphs\GraphRegistry` + `Graphs\Graph` definitions; `TransferService::attachPhoto()`; `SalesService` split + `quoteSplit()`.
* Admin: *Graphs* list + graph page (chart widget + table); transfer photo fields and gallery; sale pricing fields; report grouping and columns.
* Phone: transfer photos; sale pricing.
* Tests: every graph builds in 3 languages with and without profit permission; search; photos required / who may add / shown; split maths (rounding, edited shares, zero cost, currencies), report percentages.
