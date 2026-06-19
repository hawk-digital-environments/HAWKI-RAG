<!--
  @component Shared HAWKI-RAG dashboard navigation used by Svelte-owned page shells.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';
    import {dashboardNavItems, type DashboardNavItem} from '../util/pageNavigation.js';

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Key for the current route, used to mark the active link. */
        active: string;
        /** Stable DOM id for the refresh button used by page scripts. */
        refreshId: string;
        /** Optional replacement navigation items. */
        navItems?: DashboardNavItem[];
        /** Optional refresh handler; defaults to a full page reload. */
        onrefresh?: () => void;
    }

    const {
        active,
        refreshId,
        navItems = dashboardNavItems(),
        onrefresh,
        class: className = '',
        ...restProps
    }: Props = $props();

    function refresh(): void {
        if (onrefresh) {
            onrefresh();
            return;
        }

        window.location.reload();
    }
</script>

<nav {...restProps} class={['pipeline-nav', className].filter(Boolean).join(' ')} aria-label="HAWKI RAG pages">
    <button type="button" class="secondary-button pipeline-nav-refresh" id={refreshId} onclick={refresh}>Refresh</button>
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
