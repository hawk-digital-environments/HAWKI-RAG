<!--
  @component Minimal HAWKI-RAG admin landing page with the primary operator destinations.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';

    interface ExperienceRoute {
        key: string;
        label: string;
        title: string;
        href: string;
        summary: string;
    }

    interface Props extends HTMLAttributes<HTMLDivElement> {
        /** Browser-facing operator routes shown as dashboard cards. */
        operatorRoutes: ExperienceRoute[];
    }

    const {
        operatorRoutes,
        class: className = '',
        ...restProps
    }: Props = $props();

    const visibleRoutes = $derived(operatorRoutes.filter((route) => ['pipeline', 'datasets', 'graph', 'retrieve'].includes(route.key)));

    function cardAccent(key: string): string {
        if (key === 'pipeline') return 'green';
        if (key === 'datasets') return 'blue';
        if (key === 'graph') return 'amber';
        return 'cyan';
    }
</script>

<div {...restProps} class={['container', 'hawki-admin', 'hawki-page-shell', className].filter(Boolean).join(' ')}>
    <HawkiRagBackground />

    <DashboardHeader
        eyebrow="HAWKI-RAG admin"
        title="Admin Dashboard"
        copy="Open the primary HAWKI-RAG workspaces from one compact control surface."
        active=""
    />

    <main class="admin-shell" aria-labelledby="admin-destinations-title">
        <h2 id="admin-destinations-title" class="admin-section-title">Workspaces</h2>
        <section class="admin-cards" aria-label="Admin destinations">
            {#each visibleRoutes as route (route.key)}
                <a class="admin-card" data-accent={cardAccent(route.key)} href={route.href} aria-label={route.title}>
                    <strong>{route.label}</strong>
                    <small>{route.summary}</small>
                    <span aria-hidden="true">Open</span>
                </a>
            {/each}
        </section>
    </main>
</div>

<style>
    .hawki-admin {
        --admin-surface: rgba(8, 17, 31, 0.72);
        --admin-surface-strong: rgba(15, 23, 42, 0.88);
        --admin-border: rgba(148, 163, 184, 0.22);
        --admin-border-strong: rgba(45, 212, 191, 0.52);
        --admin-text: #f8fafc;
        --admin-muted: #bae6fd;
        --admin-accent: #67e8f9;
        --admin-green: #34d399;
        --admin-blue: #7dd3fc;
        --admin-amber: #f4d35e;
        --admin-shadow: 0 22px 55px rgba(15, 23, 42, 0.34);

        position: relative;
        isolation: isolate;
    }

    .admin-shell {
        position: relative;
        display: grid;
        gap: 14px;
        width: 100%;
        margin: 0 auto;
    }

    .admin-section-title {
        margin: 0;
        color: var(--admin-text);
        font-size: 1rem;
        font-weight: 850;
        line-height: 1.2;
        letter-spacing: 0;
    }

    .admin-cards {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .admin-card {
        --card-accent: var(--admin-green);

        position: relative;
        display: grid;
        grid-template-rows: auto minmax(64px, 1fr) auto;
        gap: 12px;
        min-height: 184px;
        overflow: hidden;
        border: 1px solid var(--admin-border);
        border-radius: 8px;
        padding: 16px;
        background: var(--admin-surface);
        box-shadow: var(--admin-shadow);
        color: var(--admin-text);
        text-decoration: none;
        transition:
            transform 180ms ease,
            border-color 180ms ease,
            background 180ms ease;
    }

    .admin-card[data-accent="blue"] {
        --card-accent: var(--admin-blue);
    }

    .admin-card[data-accent="amber"] {
        --card-accent: var(--admin-amber);
    }

    .admin-card[data-accent="cyan"] {
        --card-accent: var(--admin-accent);
    }

    .admin-card::before {
        content: "";
        position: absolute;
        inset: 0;
        border-left: 4px solid var(--card-accent);
        pointer-events: none;
    }

    .admin-card:hover,
    .admin-card:focus-visible {
        background: var(--admin-surface-strong);
        border-color: color-mix(in srgb, var(--card-accent) 68%, var(--admin-border));
        transform: translateY(-2px);
    }

    .admin-card:focus-visible {
        outline: 3px solid color-mix(in srgb, var(--card-accent) 42%, transparent);
        outline-offset: 4px;
    }

    .admin-card strong {
        color: var(--admin-text);
        font-size: 1.12rem;
        font-weight: 850;
        letter-spacing: 0;
        line-height: 1.15;
    }

    .admin-card small {
        color: var(--admin-muted);
        font-size: 0.9rem;
        line-height: 1.45;
    }

    .admin-card > span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        justify-self: start;
        min-height: 34px;
        border: 1px solid color-mix(in srgb, var(--card-accent) 58%, transparent);
        border-radius: 8px;
        padding: 0 11px;
        background: color-mix(in srgb, var(--card-accent) 12%, transparent);
        color: var(--card-accent);
        font-size: 0.82rem;
        font-weight: 850;
    }

    @media (max-width: 1100px) {
        .admin-cards {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 680px) {
        .admin-cards {
            grid-template-columns: 1fr;
        }
    }
</style>
