<!--
  @component Shared animated HAWKI-RAG admin background for operator pages.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type P5 from 'p5';
    import type {HTMLAttributes} from 'svelte/elements';

    type P5Constructor = new (sketch: (p: P5) => void, node?: HTMLElement) => P5;

    interface FloatingNode {
        x: number;
        y: number;
        vx: number;
        vy: number;
        radius: number;
        phase: number;
        token: string;
        accent: number;
    }

    interface Props extends HTMLAttributes<HTMLDivElement> {}

    const DATA_TOKENS = ['doc', 'node', 'edge', 'chunk', 'rank', 'vec', 'kg', 'rag'];
    const PALETTE = ['#36d399', '#60a5fa', '#f6c453', '#fb7185', '#22d3ee'];

    const {
        class: className = '',
        ...restProps
    }: Props = $props();

    let backgroundHost: HTMLDivElement;

    function createBackgroundSketch(P5Constructor: P5Constructor, host: HTMLDivElement, reduceMotion: boolean): P5 {
        return new P5Constructor((p: P5) => {
            let nodes: FloatingNode[] = [];

            function createNodes(): void {
                const count = Math.max(22, Math.min(54, Math.floor((p.width * p.height) / 27000)));

                nodes = Array.from({length: count}, (_, index) => ({
                    x: p.random(p.width),
                    y: p.random(p.height),
                    vx: p.random(-0.075, 0.075),
                    vy: p.random(-0.055, 0.055),
                    radius: p.random(2.4, 5.8),
                    phase: p.random(p.TWO_PI),
                    token: DATA_TOKENS[index % DATA_TOKENS.length],
                    accent: index % PALETTE.length,
                }));
            }

            function drawGrid(): void {
                p.stroke(255, 255, 255, 12);
                p.strokeWeight(1);
                for (let x = 0; x < p.width; x += 72) {
                    p.line(x, 0, x, p.height);
                }
                for (let y = 0; y < p.height; y += 72) {
                    p.line(0, y, p.width, y);
                }
            }

            function drawConnections(): void {
                for (let i = 0; i < nodes.length; i += 1) {
                    for (let j = i + 1; j < nodes.length; j += 1) {
                        const first = nodes[i];
                        const second = nodes[j];
                        const distance = p.dist(first.x, first.y, second.x, second.y);

                        if (distance > 165) continue;

                        const alpha = p.map(distance, 0, 165, 76, 0);
                        p.stroke(226, 232, 240, alpha);
                        p.strokeWeight(0.8);
                        p.line(first.x, first.y, second.x, second.y);
                    }
                }
            }

            function updateNode(node: FloatingNode): void {
                if (!reduceMotion) {
                    const tempo = Math.min(p.deltaTime / 16.67, 2);
                    node.x += node.vx * tempo;
                    node.y += node.vy * tempo;
                    node.x += Math.sin(p.frameCount * 0.003 + node.phase) * 0.025;
                    node.y += Math.cos(p.frameCount * 0.0025 + node.phase) * 0.02;
                }

                if (node.x < -24) node.x = p.width + 24;
                if (node.x > p.width + 24) node.x = -24;
                if (node.y < -24) node.y = p.height + 24;
                if (node.y > p.height + 24) node.y = -24;
            }

            function drawNode(node: FloatingNode): void {
                const color = p.color(PALETTE[node.accent]);
                const pulse = reduceMotion ? 1 : 1 + Math.sin(p.frameCount * 0.018 + node.phase) * 0.14;

                p.noStroke();
                color.setAlpha(28);
                p.fill(color);
                p.circle(node.x, node.y, node.radius * 9 * pulse);

                color.setAlpha(190);
                p.fill(color);
                p.circle(node.x, node.y, node.radius * 2.1);

                p.fill(238, 244, 252, 112);
                p.textSize(10);
                p.textAlign(p.CENTER, p.CENTER);
                p.text(node.token, node.x, node.y - 16);
            }

            p.setup = () => {
                p.createCanvas(host.clientWidth, host.clientHeight).parent(host);
                p.pixelDensity(Math.min(window.devicePixelRatio || 1, 2));
                p.randomSeed(9);
                p.textFont('Inter, ui-sans-serif, system-ui, sans-serif');
                createNodes();

                if (reduceMotion) {
                    p.noLoop();
                }
            };

            p.draw = () => {
                p.clear();
                p.background(9, 17, 29, 0);
                drawGrid();

                nodes.forEach(updateNode);
                drawConnections();
                nodes.forEach(drawNode);
            };

            p.windowResized = () => {
                p.resizeCanvas(host.clientWidth, host.clientHeight);
                createNodes();
                if (reduceMotion) {
                    p.redraw();
                }
            };
        }, host);
    }

    onMount(() => {
        let sketch: P5 | null = null;
        let disposed = false;

        void import('p5').then((module) => {
            if (!backgroundHost || disposed) return;

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const P5Constructor = module.default as P5Constructor;
            sketch = createBackgroundSketch(P5Constructor, backgroundHost, reduceMotion);
        });

        return () => {
            disposed = true;
            sketch?.remove();
        };
    });
</script>

<div {...restProps} class={['hawki-rag-background', className].filter(Boolean).join(' ')} aria-hidden="true">
    <div bind:this={backgroundHost} class="hawki-rag-background__canvas"></div>
</div>

<style>
    :global(body) {
        margin: 0;
        background: #0f172a;
    }

    :global(*) {
        box-sizing: border-box;
    }

    :global(.hawki-page-shell) {
        position: relative;
        isolation: isolate;
    }

    :global(.hawki-page-shell > :not(.hawki-rag-background)) {
        position: relative;
        z-index: 1;
    }

    .hawki-rag-background {
        position: fixed;
        inset: 0;
        z-index: 0;
        overflow: hidden;
        pointer-events: none;
    }

    .hawki-rag-background::before {
        content: "";
        position: absolute;
        inset: 0;
        z-index: 2;
        background:
            radial-gradient(circle at 18% 18%, rgba(20, 184, 166, 0.1), transparent 32%),
            radial-gradient(circle at 82% 28%, rgba(59, 130, 246, 0.12), transparent 42%),
            linear-gradient(180deg, rgba(15, 23, 42, 0.58), rgba(15, 23, 42, 0.92));
    }

    .hawki-rag-background__canvas {
        position: absolute;
        inset: 0;
        z-index: 1;
        opacity: 0.5;
    }

    .hawki-rag-background__canvas :global(canvas) {
        display: block;
        width: 100%;
        height: 100%;
    }
</style>
