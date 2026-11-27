{{--
    Menus: every word starts with a capital letter (the doctor's choice) — "Item/Group/Subgroup
    Stock", "PL / Invoice / Dual Use / COO". Shown that way, the texts themselves are unchanged
    (Arabic and Kurdish have no capitals). Long names wrap instead of being cut off.
--}}
<style>
    .fi-sidebar-group-label,
    .fi-sidebar-item-label,
    .fi-topbar-item-label {
        text-transform: capitalize;
    }

    .fi-sidebar-item-label {
        white-space: normal;
        overflow-wrap: anywhere;
    }

    /* The fold / unfold button sits at the top of the side menu (<x-sidebar-toggle />), not in the
       top bar; computers only (a phone opens the menu from the top bar). */
    .fi-topbar-collapse-sidebar-btn-ctn {
        display: none !important;
    }

    .ot-sidebar-toggle {
        display: none;
        padding: 0 0.25rem 0.5rem;
    }

    @media (min-width: 1024px) {
        .ot-sidebar-toggle {
            display: flex;
        }
    }

    /* Page numbers above a list (<x-table-top-pagination />), separated from the rows. */
    .ot-pagination-top {
        border-bottom: 1px solid var(--gray-200);
    }

    .dark .ot-pagination-top {
        border-bottom-color: rgb(255 255 255 / 0.1);
    }
</style>
