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
        padding: 0 0.25rem;
        margin-bottom: -0.5rem;
    }

    @media (min-width: 1024px) {
        .ot-sidebar-toggle {
            display: flex;
        }
    }

    /* A thinner side menu: rows and sections closer together. */
    .fi-sidebar .fi-sidebar-item-btn,
    .fi-sidebar .fi-sidebar-group-btn {
        padding-top: 0.3rem;
        padding-bottom: 0.3rem;
    }

    .fi-sidebar .fi-sidebar-group-items,
    .fi-sidebar .fi-sidebar-group {
        row-gap: 0.0625rem;
    }

    .fi-sidebar .fi-sidebar-nav-groups {
        row-gap: 1rem;
    }

    .fi-sidebar .fi-sidebar-nav {
        row-gap: 1rem;
        /* Less empty space above the fold button at the top of the menu. */
        padding-top: 0.75rem;
    }

    /* A section's name opens its home page (SectionPage). */
    .ot-section-link:hover {
        text-decoration: underline;
        text-underline-offset: 3px;
    }

    /* Back / forward arrows at the top of every page, above its title. */
    .ot-history {
        display: flex;
        align-items: center;
        gap: 0.125rem;
        margin-inline-start: -0.5rem;
        margin-top: 1rem;
        margin-bottom: -0.75rem;
    }

    /* The windows of a section's home page, like the menu: a capital on every word. */
    .ot-section-window-label {
        text-transform: capitalize;
    }

    .ot-history button:disabled {
        opacity: 0.35;
        cursor: default;
    }

    /* Page numbers above a list (<x-table-top-pagination />), separated from the rows. */
    .ot-pagination-top {
        border-bottom: 1px solid var(--gray-200);
    }

    .dark .ot-pagination-top {
        border-bottom-color: rgb(255 255 255 / 0.1);
    }
</style>
