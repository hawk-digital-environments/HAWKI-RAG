import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-pipeline-profiles-dashboard]');

if (root) {
    const els = {
        refresh: document.getElementById('profiles-refresh'),
        newProfile: document.getElementById('profiles-new'),
        count: document.getElementById('profiles-count'),
        list: document.getElementById('profiles-list'),
        status: document.getElementById('profiles-status'),
        formTitle: document.getElementById('profiles-form-title'),
        start: document.getElementById('profiles-start'),
        form: document.getElementById('profiles-form'),
        reset: document.getElementById('profiles-reset'),
        id: document.getElementById('profile-id'),
        name: document.getElementById('profile-name'),
        description: document.getElementById('profile-description'),
        startUrls: document.getElementById('profile-start-urls'),
        sitemapUrl: document.getElementById('profile-sitemap-url'),
        maxPages: document.getElementById('profile-max-pages'),
        fileTypes: document.getElementById('profile-file-types'),
        qdrant: document.getElementById('profile-qdrant'),
        neo4j: document.getElementById('profile-neo4j'),
        graph: document.getElementById('profile-graph'),
        metadata: document.getElementById('profile-metadata'),
    };

    const state = {
        profiles: [],
        selectedProfileId: localStorage.getItem('hawkiPipelineProfileId') || '',
        mode: 'create',
        requestId: 0,
    };

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function requestJson(path, options = {}) {
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers || {}),
        };

        if (options.body && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(apiUrl(path), {
            ...options,
            headers,
        });
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};

        if (!response.ok || data.success === false) {
            throw new Error(data.message || `HTTP ${response.status}`);
        }

        return data;
    }

    function setStatus(message, tone = 'neutral') {
        els.status.textContent = message;
        els.status.dataset.tone = tone;
    }

    function renderProfiles() {
        els.list.innerHTML = '';
        els.count.textContent = `${state.profiles.length} profile${state.profiles.length === 1 ? '' : 's'}`;

        if (state.profiles.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.textContent = 'No profiles yet.';
            els.list.appendChild(empty);
            return;
        }

        state.profiles.forEach((profile) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'profile-list-item';
            if (profile.profileId === state.selectedProfileId) {
                button.classList.add('is-selected');
            }

            const top = document.createElement('span');
            top.className = 'profile-list-top';
            const title = document.createElement('strong');
            title.textContent = profile.name || profile.profileId;
            const badge = document.createElement('span');
            badge.className = `status-pill ${profile.graphEnabled ? 'is-running' : 'is-idle'}`;
            badge.textContent = profile.graphEnabled ? 'graph' : 'no graph';
            top.append(title, badge);

            const id = document.createElement('span');
            id.className = 'profile-list-id';
            id.textContent = profile.profileId;

            const meta = document.createElement('span');
            meta.className = 'profile-list-meta';
            meta.textContent = [
                `${profile.startUrls?.length || 0} URLs`,
                profile.sitemapUrl ? 'sitemap' : null,
                `${profile.maxPages || 1} max pages`,
            ].filter(Boolean).join(' | ');

            button.append(top, id, meta);
            button.addEventListener('click', () => selectProfile(profile.profileId));
            els.list.appendChild(button);
        });
    }

    function selectProfile(profileId) {
        const profile = state.profiles.find((item) => item.profileId === profileId);
        if (!profile) return;

        state.mode = 'edit';
        state.selectedProfileId = profile.profileId;
        localStorage.setItem('hawkiPipelineProfileId', profile.profileId);
        els.formTitle.textContent = `Edit ${profile.name || profile.profileId}`;
        els.start.disabled = false;
        els.id.value = profile.profileId || '';
        els.id.disabled = true;
        els.name.value = profile.name || '';
        els.description.value = profile.description || '';
        els.startUrls.value = (profile.startUrls || []).join('\n');
        els.sitemapUrl.value = profile.sitemapUrl || '';
        els.maxPages.value = profile.maxPages || 1;
        els.fileTypes.value = (profile.allowedFileTypes || []).join(', ');
        els.qdrant.value = profile.qdrantCollection || '';
        els.neo4j.value = profile.neo4jNamespace || '';
        els.graph.checked = Boolean(profile.graphEnabled);
        els.metadata.value = JSON.stringify(profile.metadata || {}, null, 2);
        renderProfiles();
        setStatus(`Editing profile ${profile.profileId}.`);
    }

    function clearForm() {
        state.mode = 'create';
        state.selectedProfileId = '';
        localStorage.removeItem('hawkiPipelineProfileId');
        els.formTitle.textContent = 'Create profile';
        els.start.disabled = true;
        els.id.disabled = false;
        els.form.reset();
        els.maxPages.value = '1';
        els.fileTypes.value = 'pdf, doc, docx';
        els.metadata.value = '{}';
        renderProfiles();
        setStatus('Ready to create a profile.');
    }

    function profilePayload() {
        let metadata = {};
        try {
            metadata = JSON.parse(els.metadata.value || '{}');
        } catch {
            throw new Error('Metadata must be valid JSON.');
        }

        return {
            profile_id: els.id.value.trim(),
            name: els.name.value.trim(),
            description: els.description.value.trim() || null,
            start_urls: splitList(els.startUrls.value),
            sitemap_url: els.sitemapUrl.value.trim() || null,
            max_pages: Number.parseInt(els.maxPages.value || '1', 10),
            allowed_file_types: splitList(els.fileTypes.value),
            graph_enabled: els.graph.checked,
            qdrant_collection: els.qdrant.value.trim() || null,
            neo4j_namespace: els.neo4j.value.trim() || null,
            metadata,
        };
    }

    function splitList(value) {
        return String(value || '')
            .split(/[\n,]+/)
            .map((item) => item.trim())
            .filter(Boolean);
    }

    async function loadProfiles({ keepSelection = true } = {}) {
        const requestId = ++state.requestId;
        const data = await requestJson('api/pipeline/profiles?limit=250');
        if (requestId !== state.requestId) return;

        state.profiles = Array.isArray(data.profiles) ? data.profiles : [];
        renderProfiles();
        if (keepSelection && state.selectedProfileId && state.profiles.some((profile) => profile.profileId === state.selectedProfileId)) {
            selectProfile(state.selectedProfileId);
        } else if (!keepSelection) {
            clearForm();
        }
        setStatus(`Loaded ${state.profiles.length} profile${state.profiles.length === 1 ? '' : 's'}.`, 'success');
    }

    async function saveProfile(event) {
        event.preventDefault();
        try {
            const payload = profilePayload();
            const isEdit = state.mode === 'edit' && state.selectedProfileId;
            const data = await requestJson(
                isEdit
                    ? `api/pipeline/profiles/${encodeURIComponent(state.selectedProfileId)}`
                    : 'api/pipeline/profiles',
                {
                    method: isEdit ? 'PUT' : 'POST',
                    body: JSON.stringify(payload),
                },
            );
            state.selectedProfileId = data.profileId;
            await loadProfiles({ keepSelection: true });
            setStatus(`Saved profile ${data.profileId}.`, 'success');
        } catch (error) {
            setStatus(error.message || 'Could not save profile.', 'error');
        }
    }

    async function startTask() {
        if (!state.selectedProfileId) return;

        els.start.disabled = true;
        setStatus(`Starting task from ${state.selectedProfileId}...`);
        try {
            const data = await requestJson(`api/pipeline/profiles/${encodeURIComponent(state.selectedProfileId)}/start-task`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            setStatus(`Started task ${data.taskId}.`, 'success');
        } catch (error) {
            setStatus(error.message || 'Could not start task from profile.', 'error');
        } finally {
            els.start.disabled = false;
        }
    }

    els.refresh.addEventListener('click', () => {
        loadProfiles({ keepSelection: true }).catch((error) => setStatus(error.message || 'Could not load profiles.', 'error'));
    });
    els.newProfile.addEventListener('click', clearForm);
    els.reset.addEventListener('click', () => {
        if (state.mode === 'edit' && state.selectedProfileId) {
            selectProfile(state.selectedProfileId);
        } else {
            clearForm();
        }
    });
    els.form.addEventListener('submit', saveProfile);
    els.start.addEventListener('click', startTask);

    clearForm();
    loadProfiles({ keepSelection: true }).catch((error) => setStatus(error.message || 'Could not load profiles.', 'error'));
}
