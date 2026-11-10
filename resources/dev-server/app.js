(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const state = {
        watch: true,
        lastState: null,
        firstLoad: true,
        loading: false,
        renderTimer: null,
        renderSeq: 0,
    };

    const controlIds = ['template', 'data', 'language', 'start', 'end', 'logo'];

    function params() {
        const query = new URLSearchParams();

        for (const id of controlIds) {
            const value = ($(id).value || '').trim();
            if ('' !== value) {
                query.set(id, value);
            }
        }

        if ($('deputy-enabled').checked && '' !== $('deputy').value.trim()) {
            query.set('deputy', $('deputy').value.trim());
        }

        query.set('inclusive', $('inclusive').checked ? '1' : '0');

        return query;
    }

    async function getJson(url) {
        const response = await fetch(url, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error(`Request failed (${response.status})`);
        }

        return response.json();
    }

    function setStatus(text) {
        $('status').textContent = text;
    }

    function showError(message) {
        const error = $('error');
        error.hidden = '' === message;
        error.textContent = message || '';
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function fillSelect(select, items, current, first) {
        const signature = items.map((item) => `${item.value}\u0000${item.label}`).join('\u0001');
        if (select.dataset.signature !== signature) {
            const previous = select.value;
            select.replaceChildren();

            for (const item of items) {
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                select.append(option);
            }

            select.dataset.signature = signature;

            if (current && [...select.options].some((option) => option.value === current)) {
                select.value = current;
            } else if (previous && [...select.options].some((option) => option.value === previous)) {
                select.value = previous;
            }
        }

        if (first && current && [...select.options].some((option) => option.value === current)) {
            select.value = current;
        }
    }

    function populate(data, first) {
        const templates = (data.templates || []).map((item) => ({
            value: item.path,
            label: item.label,
        }));
        fillSelect($('template'), templates, data.current?.template, first);

        const dataFiles = [{ value: '', label: '(none)' }].concat(
            (data.dataFiles || []).map((item) => ({ value: item.path, label: item.label })),
        );
        fillSelect($('data'), dataFiles, data.current?.data || '', first);

        const languages = (data.languages || []).map((language) => ({
            value: language,
            label: language,
        }));
        fillSelect($('language'), languages, data.current?.language || '', first);

        if (first) {
            const current = data.current || {};
            $('start').value = (current.start || '').slice(0, 10);
            $('end').value = (current.end || '').slice(0, 10);
            $('inclusive').checked = current.inclusive !== false;
            $('deputy-enabled').checked = Boolean(current.deputy);
            $('deputy').value = current.deputy || '';
            $('deputy').disabled = !$('deputy-enabled').checked;
            $('logo').value = current.logo || '';
        }
    }

    function showTab(name) {
        document.querySelectorAll('.tabs button').forEach((button) => {
            button.classList.toggle('active', button.dataset.tab === name && !button.hidden);
        });
        document.querySelectorAll('.panel').forEach((panel) => {
            panel.classList.toggle('active', panel.dataset.panel === name);
        });
    }

    function updateTabs(kind) {
        $('tab-mjml').hidden = kind !== 'mjml';
        $('tab-text').hidden = kind !== 'text';

        const active = document.querySelector('.tabs button.active');
        if (!active || active.hidden) {
            showTab('preview');
        }
    }

    function renderMeta(data) {
        const rows = [
            ['Kind', data.kind],
            ['Template', data.template],
            ['Languages', (data.languages || []).join(', ') || '—'],
        ];

        const dl = $('meta');
        dl.replaceChildren();
        for (const [label, value] of rows) {
            const dt = document.createElement('dt');
            dt.textContent = label;
            const dd = document.createElement('dd');
            dd.textContent = value;
            dl.append(dt, dd);
        }
    }

    async function render() {
        const sequence = ++state.renderSeq;

        try {
            const data = await getJson(`/api/render?${params().toString()}`);
            if (sequence !== state.renderSeq) {
                return;
            }

            if (!data.ok) {
                showError(data.error || 'Render failed');
                setStatus('Render failed');
                return;
            }

            showError('');
            state.lastState = data.state;

            $('mjml').value = data.kind === 'mjml' ? data.source : '';
            $('html').value = data.html;
            $('text').textContent = data.kind === 'text' ? data.source : '';

            if (data.kind === 'mjml') {
                $('preview').srcdoc = data.html;
            } else {
                $('preview').srcdoc = '<!doctype html><html><body style="margin:0;padding:24px;background:#fff;color:#111;font:15px/1.55 ui-monospace,Consolas,monospace"><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'
                    + escapeHtml(data.source)
                    + '</pre></body></html>';
            }

            updateTabs(data.kind);
            renderMeta(data);
            setStatus(`Rendered ${new Date().toLocaleTimeString()}`);
        } catch (error) {
            showError(error instanceof Error ? error.message : String(error));
            setStatus('Render failed');
        }
    }

    function scheduleRender() {
        clearTimeout(state.renderTimer);
        state.renderTimer = setTimeout(render, 250);
    }

    async function loadState() {
        if (state.loading) {
            return;
        }

        state.loading = true;
        try {
            const data = await getJson(`/api/state?${params().toString()}`);
            state.watch = data.watch !== false;
            populate(data, state.firstLoad);

            if (data.error) {
                showError(data.error);
            }

            if (state.firstLoad) {
                state.firstLoad = false;
                await render();
            } else if (data.state && data.state !== state.lastState) {
                await render();
            }
        } catch (error) {
            showError(error instanceof Error ? error.message : String(error));
            setStatus('Server error');
        } finally {
            state.loading = false;
        }
    }

    function openHtml() {
        const html = $('html').value;
        if ('' === html) {
            return;
        }

        const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
        window.open(url, '_blank', 'noopener');
        setTimeout(() => URL.revokeObjectURL(url), 60_000);
    }

    async function copyMjml() {
        const source = $('mjml').value;
        if ('' === source) {
            setStatus('This template is not MJML');
            return;
        }

        try {
            await navigator.clipboard.writeText(source);
            setStatus('MJML copied to clipboard');
        } catch {
            $('mjml').select();
            document.execCommand('copy');
            setStatus('MJML copied to clipboard');
        }
    }

    document.querySelectorAll('.tabs button').forEach((button) => {
        button.addEventListener('click', () => showTab(button.dataset.tab));
    });

    for (const id of controlIds) {
        $(id).addEventListener('change', scheduleRender);
    }
    $('deputy').addEventListener('input', scheduleRender);
    $('start').addEventListener('change', scheduleRender);
    $('end').addEventListener('change', scheduleRender);

    $('deputy-enabled').addEventListener('change', () => {
        $('deputy').disabled = !$('deputy-enabled').checked;
        scheduleRender();
    });

    $('inclusive').addEventListener('change', scheduleRender);
    $('reload').addEventListener('click', render);
    $('open-html').addEventListener('click', openHtml);
    $('copy-mjml').addEventListener('click', copyMjml);

    loadState().then(() => {
        if (state.watch) {
            setInterval(loadState, 1000);
        }
    });
})();
