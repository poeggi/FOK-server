'use strict';

/*
 * FOK-server admin UI.
 * Each card is a self-contained module: { id, title, view, every,
 * refresh(el) }. view picks the screen: 'dash' (default) or 'settings'
 * (behind the gear). every names a settings key holding the card's own
 * refresh interval and gives it an interval control; cards without one
 * follow the global interval if listed in LIVE. To extend the admin UI,
 * append a module to MODULES; nothing else to touch.
 */

const API = 'api.php';

async function api(action, opts) {
    const res = await fetch(API + '?action=' + action, opts);
    if (res.status === 401) { location.reload(); throw new Error('session expired'); }
    return res.json();
}

function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
}

function fmtTime(unix) {
    const d = new Date(unix * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '. ' + p(d.getHours()) + ':' + p(d.getMinutes());
}

function fmtBytes(n) {
    if (n > 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n > 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
}

// Connection states as tracked by src/ConnTrack.php.
const STATE_LABEL = {
    idle: 'idle',
    inviting: 'inviting',
    invited: 'invited by',
    connecting: 'connecting',
    playing: 'playing 1:1',
};

const MODULES = [
    {
        id: 'stats',
        title: 'Statistics',
        async refresh(box) {
            const d = await api('stats');
            box.replaceChildren();
            const grid = el('div', 'statgrid');
            const stat = (label, value) => {
                const s = el('div', 'stat');
                s.append(el('div', 'stat-value', String(value)), el('div', 'stat-label', label));
                grid.append(s);
            };
            stat('Users online', d.counts.online);
            stat('Playing 1:1', d.counts.playing);
            stat('Relaying', d.relaying);
            stat('Users registered', d.counts.registered);
            stat('Friendships', d.friendships);
            stat('Pending FS', d.friendships_pending);
            stat('Scores stored', d.scores_total);
            stat('DB entries', d.db_rows);
            stat('DB size', fmtBytes(d.db_size));
            box.append(grid);
            box.append(el('p', 'muted', 'Server v' + d.server_version + ', PHP ' + d.php +
                ', ' + fmtTime(d.now)));
        },
    },
    {
        id: 'conns',
        title: 'Connections (online clients)',
        // Own, much faster interval: a whole invite/connect/play/bye
        // cycle can happen between two global refreshes.
        every: 'admin_conns_refresh_secs',
        async refresh(box) {
            const d = await api('conns');
            box.replaceChildren();
            if (!d.conns.length) { box.append(el('p', 'muted', 'No client online.')); return; }
            const table = el('table');
            table.append(row(['ID', 'Name', 'State', 'Peer', 'Mode', 'Lat', 'Age'], 'th'));
            for (const c of d.conns) {
                const r = el('tr');
                r.classList.add('online');
                const state = el('td');
                state.append(el('span', 'badge ' + c.state, STATE_LABEL[c.state] || c.state));
                r.append(el('td', '', c.id), el('td', '', c.name === null ? '-' : c.name));
                r.append(state);
                r.append(el('td', '', c.peer === null ? '-' : c.peer));
                r.append(el('td', c.mode === 'relay' ? 'error' : '', c.mode === null ? '-' : c.mode));
                r.append(el('td', '', c.latency === null ? '-' : c.latency + ' ms'));
                r.append(el('td', 'muted', (d.now - (c.since === null ? c.last_seen : c.since)) + ' s'));
                table.append(r);
            }
            box.append(table);
            box.append(el('p', 'muted', 'Age: time since the last event of that state.'));
        },
    },
    {
        id: 'props',
        title: 'Properties',
        async refresh(box) {
            const t0 = Date.now();
            const d = await api('props');
            box.replaceChildren();
            const table = el('table');
            const prop = (k, v) => {
                const r = el('tr');
                r.append(el('td', 'muted', k), el('td', '', String(v)));
                table.append(r);
            };
            prop('PTS anchor', d.pts_anchor);
            prop('UTC now', d.utc_now);
            prop('PTS now', d.pts_now + ' ms');
            prop('Clock delta', (t0 - d.pts_now) + ' ms approx.');
            prop('Server', 'v' + d.server_version + ' (API v' + d.api_version + ', ' + d.env + ')');
            box.append(table);
        },
    },
    {
        id: 'alerts',
        title: 'Alerts',
        async refresh(box) {
            const d = await api('alerts');
            box.replaceChildren();
            if (!d.alerts.length) { box.append(el('p', 'muted', 'No alerts.')); return; }
            box.append(el('p', d.unseen ? 'error' : 'muted',
                d.unseen ? d.unseen + ' unseen alert(s)' : 'All alerts seen.'));
            const table = el('table');
            table.append(row(['Time', 'Type', 'Message'], 'th'));
            for (const a of d.alerts) {
                const r = row([fmtTime(a.created), a.type, a.message]);
                if (!a.seen) r.classList.add('unseen');
                table.append(r);
            }
            box.append(table);
            if (d.unseen) {
                const btn = el('button', '', 'Mark all seen');
                btn.onclick = async () => {
                    await api('alerts_seen', { method: 'POST' });
                    refreshModule('alerts');
                };
                box.append(btn);
            }
        },
    },
    {
        id: 'config',
        title: 'Configuration',
        view: 'settings',
        async refresh(box) {
            const d = await api('settings');
            box.replaceChildren();
            const form = el('form');
            const table = el('table');
            for (const s of d.settings) {
                const r = el('tr');
                const label = el('td', '', s.label);
                const input = el('input');
                input.type = 'number';
                input.name = s.key;
                input.min = '0';
                input.value = s.value;
                const val = el('td');
                val.append(input);
                r.append(label, val, el('td', 'muted', 'default ' + s.default));
                table.append(r);
            }
            form.append(table);
            const save = el('button', '', 'Save');
            save.type = 'submit';
            form.append(save);
            form.onsubmit = async (ev) => {
                ev.preventDefault();
                const res = await api('settings_save', { method: 'POST', body: new FormData(form) });
                alert(res.ok ? 'Saved.' : 'Failed: ' + res.error);
                refreshModule('config');
            };
            box.append(form);

            const row2 = el('div', 'exportrow');
            const exp = el('a', '', 'Export config');
            exp.href = API + '?action=config_export';
            const impLabel = el('label', '', 'Import config: ');
            const impFile = el('input');
            impFile.type = 'file';
            impFile.accept = '.json';
            impLabel.append(impFile);
            impFile.onchange = async () => {
                if (!impFile.files.length) return;
                if (!confirm('Apply this configuration to the server?')) { impFile.value = ''; return; }
                const body = form({ config: await impFile.files[0].text() });
                const res = await api('config_import', { method: 'POST', body });
                alert(res.ok ? 'Config imported.' : 'Failed: ' + res.error);
                refreshModule('config');
            };
            row2.append(exp, impLabel);
            box.append(row2);
        },
    },
    {
        id: 'load',
        title: 'Load (last 24h, per hour UTC)',
        async refresh(box) {
            const d = await api('stats');
            box.replaceChildren();
            const buckets = Object.keys(d.load).sort();
            if (!buckets.length) { box.append(el('p', 'muted', 'No traffic recorded yet.')); return; }
            const table = el('table');
            table.append(row(['Hour', 'hello', 'score', 'signal'], 'th'));
            for (const b of buckets) {
                const m = d.load[b];
                table.append(row([
                    b.slice(6, 8) + '.' + b.slice(4, 6) + '. ' + b.slice(8) + 'h',
                    m.hello || 0, m.score_submit || 0, m.signal || 0,
                ]));
            }
            box.append(table);
        },
    },
    {
        id: 'users',
        title: 'Registered users',
        async refresh(box) {
            const d = await api('users');
            box.replaceChildren();
            box.append(el('p', 'muted', d.total + ' registered, showing latest ' + d.users.length));
            const table = el('table');
            table.append(row(['ID', 'Name', 'IP', 'First', 'Last', 'N', 'Lat', ''], 'th'));
            for (const u of d.users) {
                const online = d.now - u.last_seen <= d.online_window;
                const r = row([u.id, u.name === null ? '-' : u.name, u.ip,
                    fmtTime(u.first_seen), fmtTime(u.last_seen), u.hello_count,
                    u.latency === null ? '-' : u.latency + ' ms']);
                if (online) r.classList.add('online');
                const btn = el('button', 'small', 'delete');
                btn.onclick = async () => {
                    if (!confirm('Delete player ' + u.id + '?')) return;
                    await api('delete_player', { method: 'POST', body: form({ id: u.id }) });
                    refreshModule('users');
                };
                const td = el('td');
                td.append(btn);
                r.append(td);
                table.append(r);
            }
            box.append(table);
        },
    },
    {
        id: 'scores',
        title: 'Global top 100',
        async refresh(box) {
            const d = await api('scores');
            box.replaceChildren();
            if (!d.scores.length) { box.append(el('p', 'muted', 'No scores yet.')); return; }
            const table = el('table');
            table.append(row(['#', 'Name', 'Score', 'Lvl', 'Player', 'Valid', 'Date', ''], 'th'));
            for (const s of d.scores) {
                const r = row([s.rank, s.name, s.score, s.level, s.player_id,
                    s.validated ? 'yes' : '-', s.date]);
                const btn = el('button', 'small', 'delete');
                btn.onclick = async () => {
                    if (!confirm('Delete score by ' + s.name + '?')) return;
                    await api('delete_score', { method: 'POST', body: form({ id: s.id }) });
                    refreshModule('scores');
                };
                const td = el('td');
                td.append(btn);
                r.append(td);
                table.append(r);
            }
            box.append(table);
        },
    },
    {
        id: 'backup',
        title: 'Backup and restore (database incl. config)',
        view: 'settings',
        async refresh(box) {
            const d = await api('backup_list');
            box.replaceChildren();
            const create = el('button', '', 'Create backup now');
            create.onclick = async () => { await api('backup_create', { method: 'POST' }); refreshModule('backup'); };
            box.append(create);
            const table = el('table');
            for (const b of d.backups) {
                const r = row([b.name, fmtBytes(b.size)]);
                const a = el('a', '', 'download');
                a.href = API + '?action=backup_download&file=' + encodeURIComponent(b.name);
                const td = el('td');
                td.append(a);
                r.append(td);
                table.append(r);
            }
            if (d.backups.length) box.append(table);
            const restore = el('form', 'restore');
            restore.innerHTML = '<label>Restore from file: <input type="file" name="db" accept=".db" required></label> ';
            const rbtn = el('button', '', 'Restore');
            rbtn.type = 'submit';
            restore.append(rbtn);
            restore.onsubmit = async (ev) => {
                ev.preventDefault();
                if (!confirm('Replace the LIVE database with this file?')) return;
                const body = new FormData(restore);
                const res = await api('backup_restore', { method: 'POST', body });
                alert(res.ok ? 'Restored.' : 'Failed: ' + res.error);
                refreshAll();
            };
            box.append(restore);
        },
    },
];

function row(cells, tag) {
    const r = el('tr');
    for (const c of cells) r.append(el(tag || 'td', '', String(c)));
    return r;
}

function form(obj) {
    const f = new FormData();
    for (const k in obj) f.append(k, obj[k]);
    return f;
}

const boxes = {};

// Cards on the global interval. Cards with an 'every' of their own are
// not listed; the rest refresh on page load or on their refresh button.
const LIVE = ['stats', 'props', 'alerts'];

const settings = {};
const timers = {};

async function loadSettings() {
    for (const s of (await api('settings')).settings) settings[s.key] = s.value;
}

// Intervals live in the server settings: they survive a reload and are
// editable in the settings view like everything else. 0 is off.
function schedule(name, secs, fn) {
    if (timers[name]) clearInterval(timers[name]);
    if (secs > 0) timers[name] = setInterval(fn, secs * 1000);
}

function applyIntervals() {
    schedule('live', settings.admin_refresh_secs, () => LIVE.forEach(refreshModule));
    for (const m of MODULES) {
        if (m.every) schedule(m.id, settings[m.every], () => refreshModule(m.id));
    }
}

function intervalControl(key, title) {
    const wrap = el('label', 'interval');
    const input = el('input');
    input.type = 'number';
    input.min = '0';
    input.value = settings[key];
    input.title = title + ' in seconds (0 = off)';
    input.onchange = async () => {
        const secs = Math.max(0, parseInt(input.value, 10) || 0);
        input.value = secs;
        settings[key] = secs;
        applyIntervals();
        await api('settings_save', { method: 'POST', body: form({ [key]: secs }) });
    };
    wrap.append(input, el('span', 'muted', 's'));
    return wrap;
}

function refreshModule(id) {
    const mod = MODULES.find((m) => m.id === id);
    mod.refresh(boxes[id]).catch((e) => {
        boxes[id].replaceChildren(el('p', 'error', 'Error: ' + e.message));
    });
}

function refreshAll() {
    for (const m of MODULES) refreshModule(m.id);
}

const views = {
    dash: document.getElementById('dashboard'),
    settings: document.getElementById('settings'),
};

function buildCards() {
    for (const m of MODULES) {
        const card = el('section', 'card');
        const head = el('h2', '', m.title);
        const btn = el('button', 'small refresh', 'refresh');
        btn.onclick = () => refreshModule(m.id);
        if (m.every) head.append(intervalControl(m.every, m.title + ' refresh interval'));
        head.append(btn);
        const box = el('div', 'card-body');
        card.append(head, box);
        views[m.view || 'dash'].append(card);
        boxes[m.id] = box;
    }
}

const toggle = document.getElementById('viewtoggle');
toggle.onclick = () => {
    const showSettings = views.settings.classList.contains('hidden');
    views.settings.classList.toggle('hidden', !showSettings);
    views.dash.classList.toggle('hidden', showSettings);
    toggle.classList.toggle('active', showSettings);
    toggle.title = showSettings ? 'Back to dashboard' : 'Settings';
};

(async () => {
    await loadSettings();
    buildCards();
    document.querySelector('header nav')
        .prepend(intervalControl('admin_refresh_secs', 'Dashboard refresh interval'));
    refreshAll();
    applyIntervals();
})();
