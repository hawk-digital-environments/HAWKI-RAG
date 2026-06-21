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
