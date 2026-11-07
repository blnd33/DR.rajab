# Lima / Enovis catalog · Plan

> Status: **Built for the doctor's test** (2026-11-07). Source: the doctor's workbook *SUMMARY ENOVIS Lima Items for IT System 02.11.2026.xlsx* (9 sheets: implants, instrument-set summary and instrument-set details for Hip, Knee and Shoulder).
> Goal: load his real items so he can test the system with them and tell us what is missing.

## 1. What the workbook holds

| Area | Implants | Instrument sets | Notes |
|---|---|---|---|
| Hip | 591 | 50 | H-MAX S/C, Master SL, Modulus, Revision, heads, Delta cups and liners, SYMBOL dual mobility (Dedienne Santé, distributed by Lima) |
| Knee | 911 | 37 | Multigen Plus PS / CCK / H, Physica KR / CR-LMC / PS |
| Shoulder | 332 | 41 | SMR (anatomic, reverse, revision, stemless), PRIMA |
| **Total** | **1,834** | **110 distinct** | **2,296 distinct instrument codes** in the set details |

Codes are Lima catalog numbers (`4250.20.080` = H-MAX S stem #8; `9…` = instruments; `9xxx.xx.000` = a whole set). SYMBOL items use 13-digit EAN codes. No prices, no GTINs for Lima items, no quantities inside sets.

**Colours** are the doctor's own markings and differ per sheet — each sheet carries its own key (a coloured cell in column D, its meaning in column E):

| Sheet | Colours |
|---|---|
| Implants (3 sheets) | green most used · light green less used · light peach sometimes · orange rarely · red not used / discontinued |
| Hip set summary | green most used · light green less used · light peach *almost never ordered* · amber *additional, on request* · black headings |
| Knee set summary | green *used frequently* · **yellow *used rarely or never*** · light green extra single instruments |
| Shoulder set summary | green *will be ordered* · red *will not be ordered* |
| Hip / knee set details | **yellow = set name row**, uncoloured = contents, red = sets of systems not used |
| Shoulder set details | **amber = set name row, yellow = contents** |

## 2. Decisions

| # | Topic | Decision |
|---|---|---|
| L-1 | **New import type** | *Supplier catalog (Lima / Enovis workbook)* in Data imports, permission `products.import`. It reads the workbook **as the doctor sent it** — no template to fill. Same flow as the other imports: upload → preview (new / updated / unchanged / errors, old → new) → confirm → applied in the background. Re-importing a newer version of the file updates names, usage, categories and set contents. |
| L-2 | **Colours by meaning, not by colour** | Each sheet's key is read and its text mapped to a usage level (*most commonly / used frequently / will be ordered* → Most used; *less commonly* → Less used; *sometimes* → Sometimes; *rarely / almost never ordered* → Rarely; *not used / will not be ordered* → Not used). The same colour can mean different things on different sheets, so no colour is hard-coded. |
| L-3 | **Usage level** | New `usage_level` on products and kit types: `most_used`, `less_used`, `sometimes`, `rarely`, `not_used`. *Not used* items are imported **archived** (inactive): hidden from pickers and receiving, kept for history and recalls. Product search lists most-used items first. Admin lists show the level with a filter. |
| L-4 | **Implants → products** | SKU = REF code. Name (English) = the description, cleaned (spacing, the file's typos *Ssytem / SAPCER / PARTICAL / MODULS / Taber / Ti6AI4V*). Category = **Area → section** (e.g. *Hip → Acetabular Cups*), created automatically. Brand = *Lima (Enovis)*, or *Dedienne Santé* for SYMBOL. Size read from the description (`#8`, `Ø 28 mm`, `H 10 mm`, `Size Large`, `Right` / `Left`). Implant, unit *pc*, expiry required. SYMBOL EAN-13 codes are also stored as the GTIN. |
| L-5 | **Tracking type** | **Lot + quantity** for every implant until the doctor sends a photo of a real Lima label: lot tracking works whether or not the label carries a serial (SN), and recalls work by lot. Can be switched per product while it has no stock. |
| L-6 | **Instrument sets → kit types** | Every set code (`9xxx.xx.000…`) becomes a kit type with its name, usage (summary sheet first, red details block → Not used) and a description *Area · family heading*. Its **contents** come from the details sheet: the rows under the set name until the next set; a code listed twice in one set means quantity 2; items under *Additional instruments – upon request* are optional. Re-import replaces the contents. |
| L-7 | **Instruments → instrument types** | Every other instrument code (details and summary) becomes an instrument type (code + name). Italian words in names (RASPA, BATTITORE…) are kept as written — translation needs the doctor's confirmation. |
| L-8 | **Problems in the file** | Same REF for two different items (`5885.42.262`: ceramic liner *and* dual-mobility coupling) → error row, not imported until the doctor decides. The same REF listed twice for the same item (Modulus necks) → one product. Text in the code column and stray spaces are cleaned or skipped. |
| L-9 | **Learn on first scan** | Lima boxes carry a GS1 barcode with a GTIN, not the REF. When **Receive stock** (phone) scans a GTIN the system doesn't know, it asks *Which product is this?* (search by REF or name), saves that barcode on the product (as its GTIN, or as an extra barcode if it already has one) and adds the box with the lot and expiry read from the label. New permission `products.link_barcodes`, given to every role that can receive stock; logged in the activity log. |
| L-10 | **Not in this round** | Prices (not in the file), quantities inside sets, Arabic / Kurdish item names (surgeons use the English names), translating the Italian instrument words, physical kits (they are created per real box when the sets arrive). |

## 3. Build list
* **Migration** `2026_11_07_000100_add_usage_level_and_barcode_learning`: `products.usage_level`, `kit_types.usage_level`; permission `products.link_barcodes` for roles that can receive stock.
* **Enums**: `UsageLevel`; `ImportType::SupplierCatalog`.
* **Services**: `Imports/Lima/LimaWorkbookReader` (workbook → implant / instrument / set records, colours, headings, sizes, cleaning), `Imports/Handlers/SupplierCatalogRowHandler`, `Catalog/SupplierCatalogService` (categories, instrument types, kit types and their contents), `ProductService::learnBarcode`.
* **Import pipeline**: handlers may read the workbook themselves (`ReadsWorkbook`); no template download for this type.
* **Admin**: usage column + filter on products and kit types; import preview shows sheet, row and item name.
* **Phone**: Receive stock → unknown barcode → link to a product.
* **Tests**: reader on a small generated workbook (colour keys per sheet, set contents, optional items, duplicates and conflicts, sizes, typos), full import end to end, re-import, learn-on-scan (permission, GTIN vs extra barcode, activity), translations.

## 4. Questions for the doctor
1. `5885.42.262` — ceramic liner 40 mm Large, or dual-mobility coupling 40 mm Large?
2. Prices — separate price list, or entered later?
3. Discontinued (red) items — keep archived (current choice) or leave out completely?
4. A photo of a real Lima implant label — serial number (SN) or only LOT?
5. Item names in English only?
6. The 4 old Physica instrument sets — does he still own them?
