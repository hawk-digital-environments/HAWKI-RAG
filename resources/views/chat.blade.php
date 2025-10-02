<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>RAG Chat</title>

    <!-- Tailwind (CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        .glass {
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(148, 163, 184, .18);
        }

        .bubble {
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.45;
        }

        .shadow-glow {
            box-shadow: 0 10px 40px rgba(34, 197, 94, .15);
        }

        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, .3);
            border-radius: 9999px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }
    </style>
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-950 to-black text-slate-100">

    <!-- Header -->
    <header class="max-w-6xl mx-auto px-4 pt-8">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-2xl bg-gradient-to-tr from-emerald-400 to-cyan-400 grid place-items-center text-slate-900 font-bold">R</div>
                <div>
                    <h1 class="text-xl font-semibold">RAG Chat</h1>
                    <p class="text-xs text-slate-400">Streaming via <code>/api/qdrant-search</code></p>
                </div>
            </div>
            <div class="text-xs text-slate-400">Qdrant + (GWDG | Ollama)</div>
        </div>
    </header>

    <!-- Controls -->
    <section class="max-w-6xl mx-auto px-4 mt-6">
        <div class="glass rounded-2xl p-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">Provider</span>
                    <select id="provider" class="h-10 rounded-lg border border-slate-700 bg-slate-900/60 px-3 text-sm">
                        <option value="gwdg">GWDG</option>
                        <option value="ollama">Ollama</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">Top-K</span>
                    <input id="topk" type="number" min="1" max="50" value="3"
                        class="h-10 w-24 rounded-lg border border-slate-700 bg-slate-900/60 px-3 text-sm" />
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <button id="clearBtn" class="h-10 rounded-lg border border-emerald-600/40 bg-emerald-900/40 px-4 text-sm hover:bg-emerald-800/40 transition">Clear</button>
                    <button id="stopBtn" disabled class="h-10 rounded-lg border border-rose-600/40 bg-rose-900/40 px-4 text-sm disabled:opacity-50 hover:bg-rose-800/40 transition">Stop</button>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-400">
                Always using <code>{"query": "...", "top_k", "provider"}</code> for RAG accuracy.
            </p>
        </div>
    </section>

    <!-- Chat -->
    <main class="max-w-6xl mx-auto px-4 mt-6">
        <div class="relative">
            <div id="metaPanel" class="pointer-events-none absolute left-3 top-3 z-10 flex flex-col gap-1"></div>
            <div id="chat"
                class="glass min-h-[60vh] max-h-[66vh] rounded-2xl p-4 overflow-auto space-y-6">
                <div class="text-xs text-slate-400">Ask a question and watch the AI stream an answer (using direct query mode).</div>
            </div>
        </div>
    </main>

    <!-- Composer -->
    <footer class="max-w-6xl mx-auto px-4 mt-6 pb-10">
        <div class="glass rounded-2xl p-3">
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <textarea id="input" rows="2" placeholder="Ask… e.g. “who is Vincent Timm? name his projects”"
                        class="w-full rounded-xl border border-slate-700 bg-slate-900/60 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/40 shadow-glow"></textarea>
                    <p class="mt-1 text-[11px] text-slate-400">Press <kbd class="px-1 py-0.5 rounded bg-slate-800 border border-slate-700">Enter</kbd> to send · <kbd class="px-1 py-0.5 rounded bg-slate-800 border border-slate-700">Shift</kbd>+Enter for newline</p>
                </div>
                <button id="sendBtn" class="h-12 shrink-0 rounded-xl border border-emerald-600/50 bg-emerald-500/10 px-5 text-sm hover:bg-emerald-500/20 transition">Send</button>
            </div>
        </div>
    </footer>

    <script>
        (() => {
            const API_URL = '/api/qdrant-search';
            const chatEl = document.getElementById('chat');
            const metaPanel = document.getElementById('metaPanel');
            const inputEl = document.getElementById('input');
            const providerEl = document.getElementById('provider');
            const topkEl = document.getElementById('topk');
            const sendBtn = document.getElementById('sendBtn');
            const stopBtn = document.getElementById('stopBtn');
            const clearBtn = document.getElementById('clearBtn');

            let controller = null;

            // --- helpers ---
            function chip(text) {
                const span = document.createElement('span');
                span.className = "pointer-events-auto inline-flex items-center gap-1 rounded-full border border-slate-700 bg-slate-900/80 px-2.5 py-1 text-[11px] text-slate-200 shadow";
                span.textContent = text;
                return span;
            }

            function showMeta({
                provider,
                top_k,
                term
            }) {
                metaPanel.innerHTML = '';
                metaPanel.appendChild(chip(`provider: ${provider}`));
                metaPanel.appendChild(chip(`top-k: ${top_k}`));
                if (term) metaPanel.appendChild(chip(`term: ${term}`));
            }

            function statusLine(text) {
                const p = document.createElement('p');
                p.className = "text-xs text-slate-400";
                p.textContent = text;
                chatEl.appendChild(p);
                chatEl.scrollTop = chatEl.scrollHeight;
            }

            function makeUserBubble(text) {
                const row = document.createElement('div');
                row.className = "flex items-start gap-3 justify-end";
                const b = document.createElement('div');
                b.className = "bubble max-w-[70%] rounded-2xl px-4 py-2 border border-indigo-500/20 bg-gradient-to-br from-indigo-900/60 to-indigo-900/30";
                b.textContent = text;
                row.appendChild(b);
                row.appendChild(userAvatar());
                chatEl.appendChild(row);
                chatEl.scrollTop = chatEl.scrollHeight;
            }

            function makeAIBubble() {
                const row = document.createElement('div');
                row.className = "flex items-start gap-3";
                row.appendChild(botAvatar());
                const b = document.createElement('div');
                b.className = "bubble max-w-[70%] rounded-2xl px-4 py-2 border border-emerald-500/20 bg-gradient-to-br from-slate-900/60 to-emerald-900/20";
                row.appendChild(b);
                chatEl.appendChild(row);
                chatEl.scrollTop = chatEl.scrollHeight;
                return b;
            }
            const userAvatar = () => {
                const a = document.createElement('div');
                a.className = "h-9 w-9 rounded-full bg-gradient-to-br from-indigo-400 to-fuchsia-400 grid place-items-center text-slate-900 text-xs font-bold";
                a.textContent = "U";
                return a;
            };
            const botAvatar = () => {
                const a = document.createElement('div');
                a.className = "h-9 w-9 rounded-full bg-gradient-to-br from-emerald-400 to-cyan-400 grid place-items-center text-slate-900 text-xs font-bold";
                a.textContent = "AI";
                return a;
            };

            function setStreaming(active) {
                sendBtn.disabled = active;
                stopBtn.disabled = !active;
            }

            // --- main send ---
            async function send() {
                const content = (inputEl.value || "").trim();
                if (!content) return;
                makeUserBubble(content);
                const aiBubble = makeAIBubble();

                const provider = providerEl.value;
                const top_k = Math.max(1, Math.min(50, parseInt(topkEl.value || '3', 10)));
                const body = JSON.stringify({
                    query: content,
                    top_k,
                    provider
                }); // <--- always QUERY

                showMeta({
                    provider,
                    top_k
                });

                let buffer = '';
                controller = new AbortController();
                setStreaming(true);

                try {
                    const res = await fetch(API_URL, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body,
                        signal: controller.signal,
                    });
                    if (!res.ok || !res.body) {
                        statusLine(`HTTP ${res.status}`);
                        return;
                    }

                    const reader = res.body.getReader();
                    const dec = new TextDecoder();

                    while (true) {
                        const {
                            value,
                            done
                        } = await reader.read();
                        if (done) break;
                        buffer += dec.decode(value, {
                            stream: true
                        });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';

                        for (const raw of lines) {
                            const line = raw.trim();
                            if (!line) continue;
                            let obj;
                            try {
                                obj = JSON.parse(line);
                            } catch {
                                continue;
                            }
                            if (obj.type === 'ragResponse') {
                                aiBubble.textContent += obj.choices?.[0]?.delta?.content || '';
                                chatEl.scrollTop = chatEl.scrollHeight;
                            }
                            if (obj.type === 'ragMetadata') {
                                const md = obj.metadata || {};
                                showMeta({
                                    provider,
                                    top_k,
                                    term: md.term
                                });
                                if (Array.isArray(md.retrieved)) {
                                    statusLine('Retrieved:');
                                    md.retrieved.forEach(r => statusLine(`- ${r.title ?? '[untitled]'} (score=${r.score})`));
                                }
                                statusLine(`✓ done • term="${md.term}" • results=${md.results_count ?? '?'} • ${JSON.stringify(md.performance||{})}`);
                            }
                        }
                    }
                } catch (e) {
                    if (e?.name === 'AbortError') statusLine('Stream aborted');
                    else statusLine('Error: ' + e.message);
                } finally {
                    controller = null;
                    setStreaming(false);
                    inputEl.value = '';
                    inputEl.focus();
                }
            }

            // --- events ---
            sendBtn.addEventListener('click', send);
            inputEl.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    send();
                }
            });
            stopBtn.addEventListener('click', () => {
                if (controller) controller.abort();
            });
            clearBtn.addEventListener('click', () => {
                chatEl.innerHTML = '<div class="text-xs text-slate-400">Ask a question…</div>';
                metaPanel.innerHTML = '';
            });
        })();
    </script>
</body>

</html>