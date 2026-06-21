<!--
  @component Shared HAWKI-RAG dashboard navigation used by Svelte-owned page shells.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';
    import {dashboardNavItems, type DashboardNavItem} from '../util/pageNavigation.js';

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Key for the current route, used to mark the active link. */
        active: string;
        /** Optional replacement navigation items. */
        navItems?: DashboardNavItem[];
    }

    const {
        active,
        navItems = dashboardNavItems(),
        class: className = '',
        ...restProps
    }: Props = $props();
</script>

<nav {...restProps} class={['pipeline-nav', className].filter(Boolean).join(' ')} aria-label="HAWKI RAG pages">
    {#each navItems as item (item.key)}
        <a
            class={['secondary-link', active === item.key && 'is-active'].filter(Boolean).join(' ')}
            href={item.href}
            aria-current={active === item.key ? 'page' : undefined}
        >
            {item.label}
        </a>
    {/each}
</nav>

<style>
    .pipeline-nav {
        --dashboard-nav-width: 132px;
        --dashboard-nav-height: 40px;

        display: flex;
        align-items: stretch;
        justify-content: flex-end;
        gap: 0.6rem;
        min-width: 0;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        scrollbar-width: thin;
    }

    .pipeline-nav .secondary-link {
        display: inline-flex;
        flex: 0 0 var(--dashboard-nav-width);
        align-items: center;
        justify-content: center;
        width: var(--dashboard-nav-width);
        min-width: var(--dashboard-nav-width);
        height: var(--dashboard-nav-height);
        min-height: var(--dashboard-nav-height);
        padding: 0 0.75rem;
        box-sizing: border-box;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    @media (max-width: 720px) {
        .pipeline-nav {
            justify-content: flex-start;
            width: 100%;
        }
    }
</style>
