# Phase 2 — Movement · Plan

> Status: **Built and verified 2026-10-26** (straight after Phase 1 — the client asked to run the remaining phases back-to-back). 185 tests green, Larastan 0, browser run-through in English and Sorani. Changes from this plan: see [CHANGELOG](../../CHANGELOG.md) § Phase 2.
> Brief scope: *Transfers, stock requests + approval, returns, write-offs, stock count, notifications.* Modules 8, 9, 14, 15, 16, 25 (+ Module 1 close actions, Module 17 "request from another branch").
> Tables: [DATABASE.md](../DATABASE.md) § Phase 2.

## 1. Decisions

| # | Topic | Decision |
|---|---|---|
| P2-1 | **Units in transit** | On *send*: units → `in_transit` (branch stays the sender until received); lot quantity leaves the sender's bucket and lives on the transfer line (with its cost layer). `v_stock_on_hand` counts both as "in transit" of the sending branch. |
| P2-2 | **Receive** | The receiver scans every unit / enters lot quantities. Not scanned → line `missing`, short lots → `short`, scanned but not on the slip → `extra` (not moved). Any difference → transfer `received_with_discrepancy` + an **issue** for the Super Admin, who resolves each line: *found* (receive it now), *returned to sender*, or *written off* (lost). |
| P2-3 | **Stock requests** | Branch asks for products + quantities (optionally from a preferred branch). The approver edits quantities and picks the source branch per line (suggestion: the branch with the most valid stock, FEFO). Approval creates one **draft transfer per source branch**, which the source branch fills by scanning and sends. Request becomes `fulfilled` when all its transfers are received. |
| P2-4 | **Write-offs** | Report (units / lots, reason damaged / lost / expired, note, photos) → `pending` → approve / reject (`write_offs.approve`). Only approval moves stock (units → damaged / lost / expired_written_off, lot quantity removed), with cost recorded. Units in a pending write-off cannot be sent or counted twice. |
| P2-5 | **Returns** | Customer returns: units currently out of our stock (`out_at_hospital`, `used_in_surgery`, `sold`) or lots → back into a branch. Condition *good* → shelf; *damaged* → back into stock **and** a pending write-off is created. Supplier returns: units / lots → `returned_to_supplier` on *send*; credit notes and balances arrive with Phase 4 (`credit_note_id`, `fx_snapshot_id` stay empty). |
| P2-6 | **Stock count** | Start (full / category / shelf) → expected lines snapshot → scan units / enter lot counts (phone scans can be queued offline and synced with a `client_uuid`, idempotent) → submit → review: missing, quantity mismatch, extra (other branch / unknown) → resolve per line: *adjust* (count adjustment on the ledger), *write off*, *investigating*, *ignore* → close when nothing is pending. Discrepancies open an issue. |
| P2-7 | **Customers** | `customers` (hospitals, clinics, distributors, individuals) is created now for returns; kits / surgeries use it in Phase 3, receivables in Phase 4. |
| P2-8 | **Kits on transfers** | `transfer_assets` needs `kits` / `instruments` → created in Phase 3. |
| P2-9 | **Branch close** | New checks: pending transfers / requests / counts / write-offs block closing; *Transfer all stock to branch X* creates and sends one transfer with everything on the shelf. |
| P2-10 | **Phone navigation** | Bottom bar becomes Home · Stock · **Scan** · Tasks · Me (alerts stay on the bell). *Tasks* = transfers to receive / send, my requests, counts in progress, write-offs waiting. |

## 2. Build list
* **Migrations** `2026_10_27_*_p2_*`: customers, transfers + items, stock_requests + items, customer_returns + items, supplier_returns + items, write_offs + items, stock_counts + lines + scans, issues, updated stock views.
* **Services**: `TransferService`, `StockRequestService`, `WriteOffService`, `ReturnService`, `StockCountService`, `IssueService` — all stock changes through new `StockMovementService` methods (`sendUnit` / `sendBatch`, `receiveUnit` / `receiveBatch`, `returnUnitToSender` / `returnBatchToSender`, `moveUnitOut`, `removeFromBatch` / `addToBatch`, `restockUnit`, `recordLossInTransit`).
* **Admin**: Transfers, Stock requests, Customer returns, Supplier returns, Write-offs, Stock counts, Issues, Customers; branch *Transfer all stock*, low-stock *Request from another branch*; dashboard: pending approvals + open issues.
* **Ops**: Tasks hub, transfer create / send / receive by scanning, request form, stock count scanning (offline queue), write-off report, customer return.
* **Notifications**: transfer sent / received / discrepancy, request created / approved / rejected, write-off waiting / decided, count submitted.
* **Tests**: send → in transit → receive (units + lots, ledger rows, view), discrepancy + resolutions, cancel before receive, expired blocked on send, request approval → transfers → fulfilled, write-off approval moves stock only then, customer / supplier returns, count snapshot → scans → adjustments, idempotent scan sync, branch close blockers + transfer all, permissions per branch.
