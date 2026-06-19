<!--
  @component Product-level HAWKI-RAG Svelte shell for operator route tabs.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';

    type RouteState = 'ready' | 'live' | 'planned';

    interface ExperienceRoute {
        key: string;
        label: string;
        title: string;
        href: string;
        summary: string;
        service: string;
        state: RouteState;
    }

    interface CoreService {
        key: string;
        label: string;
        title: string;
        state: RouteState;
    }

    interface NodePoint {
        x: number;
        y: number;
    }

    interface Props extends HTMLAttributes<HTMLDivElement> {
        /** Route key highlighted when the page first renders. */
        activeSection?: string;
        /** Browser-facing operator routes. */
        operatorRoutes: ExperienceRoute[];
        /** Core service boundary shown below the route map. */
        coreServices: CoreService[];
    }

    const NODE_POINTS: NodePoint[] = [
        {x: 11, y: 74},
        {x: 22, y: 54},
        {x: 35, y: 66},
        {x: 48, y: 38},
        {x: 62, y: 52},
        {x: 76, y: 29},
        {x: 88, y: 43},
    ];

    const {
        activeSection = 'operator',
        operatorRoutes,
        coreServices,
        class: className = '',
        ...restProps
    }: Props = $props();

    let selectedKey = $state('');

    $effect(() => {
        if (selectedKey === '') {
            selectedKey = activeSection;
        }
    });

    const selectedRoute = $derived(
        operatorRoutes.find((route) => route.key === selectedKey) ?? operatorRoutes[0] ?? null,
    );
    const readyCount = $derived(operatorRoutes.filter((route) => route.state !== 'planned').length);
    const routePath = $derived(
        operatorRoutes
            .map((route, index) => {
                const point = pointFor(index);

                return `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`;
            })
            .join(' '),
    );

    function pointFor(index: number): NodePoint {
        return NODE_POINTS[index % NODE_POINTS.length];
    }

    function selectRoute(route: ExperienceRoute): void {
        selectedKey = route.key;
    }

    function stateLabel(state: RouteState): string {
        if (state === 'planned') return 'Next';
        if (state === 'live') return 'Live';
        return 'Ready';
    }
</script>

<div {...restProps} class={['hawki-experience', className].filter(Boolean).join(' ')}>
    <header class="experience-header">
        <div class="experience-title">
            <p>Search. Retrieve. Explore.</p>
            <h1>HAWKI-RAG</h1>
        </div>
    </header>

    <main class="experience-layout">
        <section class="brain-map" aria-label="HAWKI-RAG route map">
            <div class="brain-map__halo" aria-hidden="true"></div>
            <div class="brain-map__grid" aria-hidden="true"></div>
            <svg class="brain-map__path" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <path d={routePath} />
            </svg>

            {#each operatorRoutes as route, index (route.key)}
                {@const point = pointFor(index)}
                <button
                    type="button"
                    class="brain-node"
                    class:brain-node--active={selectedRoute?.key === route.key}
                    data-state={route.state}
                    style={`--x: ${point.x}%; --y: ${point.y}%;`}
                    aria-pressed={selectedRoute?.key === route.key}
                    onclick={() => selectRoute(route)}
                >
                    <span>{String(index + 1).padStart(2, '0')}</span>
                    <strong>{route.label}</strong>
                </button>
            {/each}

            {#if selectedRoute}
                <article class="route-focus">
                    <p>{selectedRoute.service}</p>
                    <h2>{selectedRoute.title}</h2>
                    <span>{selectedRoute.summary}</span>
                    <a href={selectedRoute.href}>Open {selectedRoute.label}</a>
                </article>
            {/if}
        </section>

        <section class="route-rail" aria-label="Operator routes">
            <div class="route-rail__head">
                <div>
                    <p>Admin / Developer</p>
                    <h2>Operator World</h2>
                </div>
                <span>{readyCount}/{operatorRoutes.length}</span>
            </div>

            <div class="route-list">
                {#each operatorRoutes as route (route.key)}
                    <a
                        class="route-item"
                        class:route-item--active={selectedRoute?.key === route.key}
                        href={route.href}
                        onfocus={() => selectRoute(route)}
                    >
                        <span class="route-item__state" data-state={route.state}>{stateLabel(route.state)}</span>
                        <strong>{route.title}</strong>
                        <small>{route.summary}</small>
                    </a>
                {/each}
            </div>
        </section>
    </main>

    <section class="service-spine" aria-label="HAWKI-RAG core services">
        {#each coreServices as service (service.key)}
            <article data-state={service.state}>
                <span>{stateLabel(service.state)}</span>
                <strong>{service.label}</strong>
                <small>{service.title}</small>
            </article>
        {/each}
    </section>
</div>

<style>
    .hawki-experience {
        --experience-bg: #06101d;
        --experience-surface: rgba(8, 18, 32, 0.82);
        --experience-surface-strong: rgba(15, 23, 42, 0.95);
        --experience-border: rgba(177, 195, 216, 0.22);
        --experience-border-strong: rgba(125, 211, 252, 0.42);
        --experience-text: #f8fafc;
        --experience-muted: #b6c3d4;
        --experience-cyan: #22d3ee;
        --experience-green: #34d399;
        --experience-amber: #f6c453;
        --experience-red: #fb7185;
        --experience-blue: #60a5fa;
        --experience-ink: #07111f;
        --experience-shadow: 0 28px 90px rgba(0, 0, 0, 0.42);

        min-height: 100vh;
        overflow: hidden;
        padding: clamp(18px, 3vw, 34px);
        background:
            radial-gradient(circle at 15% 20%, rgba(34, 211, 238, 0.16), transparent 28%),
            radial-gradient(circle at 72% 10%, rgba(52, 211, 153, 0.18), transparent 26%),
            linear-gradient(135deg, #06101d 0%, #101826 50%, #172033 100%);
        color: var(--experience-text);
        font-family: Inter, ui-sans-serif, system-ui, sans-serif;
    }

    .experience-header,
    .experience-layout,
    .service-spine,
    .route-rail__head {
        display: grid;
        gap: 16px;
    }

    .experience-header {
        grid-template-columns: minmax(0, 1fr);
        align-items: end;
        margin: 0 auto 18px;
        max-width: 1480px;
    }

    .experience-title p,
    .route-rail__head p,
    .route-focus p {
        margin: 0 0 7px;
        color: var(--experience-green);
        font-size: 0.78rem;
        font-weight: 850;
        letter-spacing: 0;
        text-transform: uppercase;
    }

    .experience-title h1 {
        margin: 0;
        max-width: 780px;
        color: var(--experience-text);
        font-size: clamp(2.25rem, 5vw, 4.8rem);
        line-height: 0.98;
        letter-spacing: 0;
    }

    .experience-layout {
        grid-template-columns: minmax(0, 1.42fr) minmax(340px, 0.58fr);
        align-items: stretch;
        margin: 0 auto;
        max-width: 1480px;
    }

    .brain-map {
        position: relative;
        min-height: min(720px, calc(100vh - 230px));
        overflow: hidden;
        border: 1px solid var(--experience-border);
        border-radius: 8px;
        background:
            linear-gradient(180deg, rgba(96, 165, 250, 0.16), transparent 46%),
            rgba(6, 16, 29, 0.76);
        box-shadow: var(--experience-shadow);
        isolation: isolate;
    }

    .brain-map__halo {
        position: absolute;
        inset: 9% 14% 11%;
        border: 1px solid rgba(34, 211, 238, 0.18);
        border-radius: 48% 52% 43% 57%;
        background:
            radial-gradient(circle at 42% 42%, rgba(52, 211, 153, 0.16), transparent 34%),
            radial-gradient(circle at 62% 48%, rgba(251, 113, 133, 0.1), transparent 28%),
            rgba(8, 18, 32, 0.42);
        filter: drop-shadow(0 24px 70px rgba(34, 211, 238, 0.16));
        z-index: 0;
    }

    .brain-map__grid {
        position: absolute;
        inset: 35% -12% -30%;
        transform: perspective(780px) rotateX(58deg);
        transform-origin: center top;
        background:
            repeating-linear-gradient(90deg, rgba(246, 196, 83, 0.22) 0 1px, transparent 1px 76px),
            repeating-linear-gradient(0deg, rgba(34, 211, 238, 0.16) 0 1px, transparent 1px 48px);
        border-top: 1px solid rgba(246, 196, 83, 0.25);
        z-index: 0;
    }

    .brain-map__path {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        z-index: 1;
    }

    .brain-map__path path {
        fill: none;
        stroke: rgba(226, 232, 240, 0.72);
        stroke-dasharray: 8 8;
        stroke-linecap: round;
        stroke-width: 1.3;
        vector-effect: non-scaling-stroke;
    }

    .brain-node {
        --node-color: var(--experience-cyan);

        position: absolute;
        left: var(--x);
        top: var(--y);
        z-index: 3;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: 8px;
        width: clamp(142px, 15vw, 210px);
        min-height: 58px;
        transform: translate(-50%, -50%);
        border: 1px solid color-mix(in srgb, var(--node-color) 58%, transparent);
        border-radius: 8px;
        padding: 9px 10px;
        background: rgba(8, 18, 32, 0.9);
        color: var(--experience-text);
        box-shadow: 0 18px 38px rgba(0, 0, 0, 0.3);
        cursor: pointer;
        font: inherit;
        text-align: left;
    }

    .brain-node[data-state="live"] {
        --node-color: var(--experience-green);
    }

    .brain-node[data-state="planned"] {
        --node-color: var(--experience-amber);
    }

    .brain-node--active {
        background: color-mix(in srgb, var(--node-color) 18%, rgba(8, 18, 32, 0.96));
        border-color: var(--node-color);
    }

    .brain-node:focus-visible {
        outline: 2px solid var(--node-color);
        outline-offset: 3px;
    }

    .brain-node span {
        display: grid;
        place-items: center;
        width: 34px;
        height: 34px;
        border-radius: 999px;
        background: var(--node-color);
        color: var(--experience-ink);
        font-size: 0.8rem;
        font-weight: 900;
    }

    .brain-node strong {
        overflow: hidden;
        font-size: 0.88rem;
        font-weight: 860;
        line-height: 1.15;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .route-focus {
        position: absolute;
        right: 22px;
        bottom: 22px;
        z-index: 4;
        width: min(390px, calc(100% - 44px));
        border: 1px solid var(--experience-border-strong);
        border-radius: 8px;
        padding: 18px;
        background: rgba(8, 18, 32, 0.94);
        box-shadow: var(--experience-shadow);
    }

    .route-focus h2 {
        margin: 0;
        color: var(--experience-text);
        font-size: clamp(1.45rem, 2.4vw, 2.1rem);
        line-height: 1;
        letter-spacing: 0;
    }

    .route-focus span {
        display: block;
        margin-top: 10px;
        color: var(--experience-muted);
        line-height: 1.5;
    }

    .route-focus a {
        display: inline-flex;
        align-items: center;
        min-height: 40px;
        margin-top: 14px;
        border-radius: 8px;
        padding: 0 14px;
        background: var(--experience-green);
        color: var(--experience-ink);
        font-weight: 880;
        text-decoration: none;
    }

    .route-rail {
        border: 1px solid var(--experience-border);
        border-radius: 8px;
        padding: 16px;
        background: var(--experience-surface);
        box-shadow: var(--experience-shadow);
    }

    .route-rail__head {
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: end;
        margin-bottom: 12px;
    }

    .route-rail__head h2 {
        margin: 0;
        font-size: 1.75rem;
        line-height: 1;
        letter-spacing: 0;
    }

    .route-rail__head > span {
        display: grid;
        place-items: center;
        min-width: 58px;
        min-height: 40px;
        border: 1px solid var(--experience-border-strong);
        border-radius: 8px;
        color: var(--experience-cyan);
        font-weight: 900;
    }

    .route-list {
        display: grid;
        gap: 9px;
    }

    .route-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        grid-template-rows: auto auto;
        gap: 5px 10px;
        min-height: 76px;
        border: 1px solid var(--experience-border);
        border-radius: 8px;
        padding: 12px;
        background: rgba(15, 23, 42, 0.62);
        color: var(--experience-text);
        text-decoration: none;
    }

    .route-item--active {
        border-color: var(--experience-border-strong);
        background: rgba(14, 116, 144, 0.18);
    }

    .route-item:focus-visible {
        outline: 2px solid var(--experience-cyan);
        outline-offset: 3px;
    }

    .route-item__state {
        grid-row: 1 / span 2;
        align-self: start;
        min-width: 52px;
        border-radius: 999px;
        padding: 5px 8px;
        background: rgba(34, 211, 238, 0.16);
        color: var(--experience-cyan);
        font-size: 0.72rem;
        font-weight: 900;
        text-align: center;
        text-transform: uppercase;
    }

    .route-item__state[data-state="live"] {
        background: rgba(52, 211, 153, 0.14);
        color: var(--experience-green);
    }

    .route-item__state[data-state="planned"] {
        background: rgba(246, 196, 83, 0.14);
        color: var(--experience-amber);
    }

    .route-item strong,
    .route-item small {
        min-width: 0;
    }

    .route-item strong {
        font-size: 0.98rem;
        line-height: 1.2;
    }

    .route-item small {
        color: var(--experience-muted);
        font-size: 0.82rem;
        line-height: 1.35;
    }

    .service-spine {
        grid-template-columns: repeat(5, minmax(0, 1fr));
        margin: 16px auto 0;
        max-width: 1480px;
    }

    .service-spine article {
        min-height: 104px;
        border: 1px solid var(--experience-border);
        border-radius: 8px;
        padding: 13px;
        background: var(--experience-surface-strong);
        box-shadow: 0 16px 38px rgba(0, 0, 0, 0.22);
    }

    .service-spine article[data-state="planned"] {
        border-color: rgba(246, 196, 83, 0.34);
    }

    .service-spine article span {
        display: inline-block;
        margin-bottom: 10px;
        color: var(--experience-cyan);
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
    }

    .service-spine article[data-state="planned"] span {
        color: var(--experience-amber);
    }

    .service-spine article strong {
        display: block;
        color: var(--experience-text);
        line-height: 1.16;
    }

    .service-spine article small {
        display: block;
        margin-top: 7px;
        color: var(--experience-muted);
        line-height: 1.35;
    }

    @media (max-width: 1080px) {
        .experience-layout,
        .service-spine {
            grid-template-columns: 1fr;
        }

        .brain-map {
            min-height: 680px;
        }
    }

    @media (max-width: 720px) {
        .hawki-experience {
            padding: 14px;
        }

        .experience-header {
            grid-template-columns: 1fr;
        }

        .brain-map {
            min-height: 760px;
        }

        .brain-node {
            width: min(176px, 48vw);
        }

        .route-focus {
            right: 12px;
            bottom: 12px;
            width: calc(100% - 24px);
        }
    }
</style>
