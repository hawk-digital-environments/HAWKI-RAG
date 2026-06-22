<!--
  @component Standard compact header for HAWKI-RAG dashboard pages.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardNav from './DashboardNav.svelte';

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Small uppercase label above the page title. */
        eyebrow: string;
        /** Main page title. */
        title: string;
        /** Short description below the page title. */
        copy: string;
        /** Active navigation key. */
        active: string;
    }

    const {
        eyebrow,
        title,
        copy,
        active,
        class: className = '',
        ...restProps
    }: Props = $props();
</script>

<header {...restProps} class={['dashboard-header', className].filter(Boolean).join(' ')}>
    <div>
        <p class="eyebrow">{eyebrow}</p>
        <h1>{title}</h1>
        <p class="header-copy">{copy}</p>
    </div>
    <div class="header-actions">
        <DashboardNav {active} />
    </div>
</header>

<style>
    .dashboard-header {
        --dashboard-header-max-width: 1760px;
        --dashboard-header-gap: 20px;
        --dashboard-header-border: rgba(148, 163, 184, 0.22);
        --dashboard-header-title: #f8fafc;
        --dashboard-header-eyebrow: #67e8f9;
        --dashboard-header-copy: #bae6fd;
        --dashboard-header-title-size: 1.4rem;
        --dashboard-header-copy-size: 0.95rem;
        --dashboard-header-eyebrow-size: 0.78rem;

        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: end;
        gap: var(--dashboard-header-gap);
        width: 100%;
        max-width: var(--dashboard-header-max-width);
        margin: 0 auto 18px;
        padding: 0 0 18px;
        border-bottom: 1px solid var(--dashboard-header-border);
    }

    .dashboard-header > div:first-child {
        min-width: 0;
    }

    .dashboard-header h1 {
        margin: 0;
        color: var(--dashboard-header-title);
        font-size: var(--dashboard-header-title-size);
        font-weight: 800;
        line-height: 1.15;
        letter-spacing: 0;
    }

    .eyebrow {
        margin: 0 0 0.35rem;
        color: var(--dashboard-header-eyebrow);
        font-size: var(--dashboard-header-eyebrow-size);
        font-weight: 800;
        line-height: 1.2;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .header-copy {
        max-width: 680px;
        margin: 8px 0 0;
        color: var(--dashboard-header-copy);
        font-size: var(--dashboard-header-copy-size);
        line-height: 1.55;
    }

    .header-actions {
        display: flex;
        flex-wrap: nowrap;
        justify-content: flex-end;
        gap: 0.6rem;
        min-width: 0;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        scrollbar-width: thin;
    }

    @media (max-width: 1100px) {
        .dashboard-header {
            grid-template-columns: 1fr;
            align-items: start;
        }

        .header-actions {
            width: 100%;
            justify-content: flex-start;
        }
    }
</style>
