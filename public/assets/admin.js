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

// Date only, no time - for columns where the time of day carries no
// information (e.g. a debug report's fixed 24 h expiry) and the extra
// width would push the card into a horizontal scrollbar.
function fmtDate(unix) {
    const d = new Date(unix * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.';
}

// How many leading words of an alert message the card shows; the full text
// is one click away in a detail popup.
const ALERT_PREVIEW_WORDS = 4;

function fmtBytes(n) {
    if (n > 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n > 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
}

// Inline SVG icons (ASCII source, inherit currentColor) for icon-only
// buttons, so there are no glyph fonts or external assets to load.
const ICON = {
    download: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" '
        + 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
        + '<path d="M8 2v8M5 7l3 3 3-3M3 13h10"/></svg>',
    trash: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" '
        + 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
        + '<path d="M3 4h10M6 4V2.5h4V4M5 4l.6 9h4.8L11 4"/></svg>',
};

function iconBtn(svg, title) {
    const b = el('button', 'small iconbtn');
    b.innerHTML = svg;
    b.title = title;
    b.setAttribute('aria-label', title);
    return b;
}

// Trigger a browser download of one debug dataset as debug-<pin>.json.
function downloadPin(pin) {
    const a = el('a');
    a.href = API + '?action=debug_get&pin=' + pin;
    a.download = 'debug-' + pin + '.json';
    document.body.append(a);
    a.click();
    a.remove();
}

// Connection states as tracked by src/ConnTrack.php.
const STATE_LABEL = {
    idle: 'idle',
    matchmaking: 'matchmaking',
    inviting: 'inviting',
    invited: 'invited by',
    connecting: 'connecting',
    playing: 'playing 1:1',
    declined: 'declined',
    ended: 'ended',
};

// What the server asked for vs what the client reports it is doing. They
// are independent: 'pending' is a wish the client has not picked up yet
// (it honours it on its next hello), 'self' is a client that turned its
// own debug mode on without being told to.
function debugLabel(c) {
    if (c.debug) return c.debug_active ? 'on' : 'pending';
    return c.debug_active ? 'self' : 'off';
}

// A client id, clickable: opens the details popup. Used wherever an id
// appears (Connections, Duels, Registered users) so any id is a way in.
function idCell(id) {
    const td = el('td');
    const s = el('span', 'id-link', id);
    s.onclick = () => showClient(id);
    td.append(s);
    return td;
}

// IPv6 is too wide for a table column: show the first and last group with
// an ellipsis and the full address on hover. IPv4 is short, shown whole.
function ipCell(ip) {
    const td = el('td');
    if (typeof ip === 'string' && ip.includes(':')) {
        const g = ip.split(':').filter((x) => x !== '');
        const s = el('span', 'trunc', g.length > 1 ? g[0] + '..' + g[g.length - 1] : ip);
        s.title = ip;
        td.append(s);
    } else {
        td.textContent = (ip === null || ip === undefined || ip === '') ? '-' : ip;
    }
    return td;
}

// One condensed popup with everything known about a client: identity,
// presence, its 1:1 / connection state, relay counters, matchmaking,
// friendships, scores and mailbox. Opened by clicking any id.
// Popup-local auto-refresh cadence (seconds), shared by every details
// popup; not a server setting. 0 = off.
let clientRefreshSecs = 2;

function closeModal(overlay) {
    if (overlay._stop) overlay._stop();
    if (overlay._onKey) document.removeEventListener('keydown', overlay._onKey);
    overlay.remove();
}

// A minimal read-only popup (no refresh timer) for content that does not fit
// inline, e.g. a full alert message. Closes on the button, click-outside or
// Escape, just like the client popup.
function infoModal(titleText, bodyNode) {
    const overlay = el('div', 'modal-backdrop');
    const modal = el('div', 'modal');
    const head = el('div', 'modal-head');
    const title = el('div', 'modal-title');
    title.append(el('span', 'modal-name', titleText));
    const close = el('button', 'small', 'close');
    close.onclick = () => closeModal(overlay);
    head.append(title, close);
    modal.append(head, bodyNode);
    overlay.append(modal);
    overlay.onmousedown = (e) => { if (e.target === overlay) closeModal(overlay); };
    const onKey = (e) => { if (e.key === 'Escape') closeModal(overlay); };
    overlay._onKey = onKey;
    document.addEventListener('keydown', onKey);
    document.body.append(overlay);
}

function showAlert(a) {
    const tbl = el('table', 'kv');
    const kv = (k, v) => { const r = el('tr'); r.append(el('td', 'kv-k', k), el('td', 'kv-v', v)); tbl.append(r); };
    kv('Time', fmtTime(a.created));
    kv('Type', a.type);
    kv('Message', a.message);
    infoModal('Alert', tbl);
}

// A refresh-interval control not backed by a server setting (the popup).
function localIntervalControl(get, set) {
    const wrap = el('label', 'interval');
    const input = el('input');
    input.type = 'number';
    input.min = '0';
    input.value = get();
    input.title = 'Auto-refresh interval in seconds (0 = off)';
    input.onchange = () => { const s = Math.max(0, parseInt(input.value, 10) || 0); input.value = s; set(s); };
    wrap.append(input, el('span', 'muted', 's'));
    return wrap;
}

async function showClient(id) {
    const overlay = el('div', 'modal-backdrop');
    const modal = el('div', 'modal');
    overlay.append(modal);

    // The head is built once and stays put; only the body re-renders, so
    // the interval control keeps focus and the popup does not flicker.
    const head = el('div', 'modal-head');
    const title = el('div', 'modal-title');
    const name = el('span', 'modal-name', id);
    title.append(name, el('span', 'modal-id', id));
    const body = el('div', 'modal-body');

    const load = async () => {
        try {
            const d = await api('client&id=' + id);
            if (!d.ok) throw new Error(d.error || 'failed');
            name.textContent = d.client.name || '(no name)';
            renderClientBody(body, overlay, d, load);
        } catch (e) {
            body.replaceChildren(el('p', 'error', 'Error: ' + e.message));
        }
    };

    let timer = 0;
    const restart = () => {
        if (timer) clearInterval(timer);
        timer = clientRefreshSecs > 0 ? setInterval(load, clientRefreshSecs * 1000) : 0;
    };
    overlay._stop = () => { if (timer) clearInterval(timer); };

    const ctl = localIntervalControl(() => clientRefreshSecs, (s) => { clientRefreshSecs = s; restart(); });
    const refresh = el('button', 'small refresh', 'refresh');
    refresh.onclick = load;
    const close = el('button', 'small', 'close');
    close.onclick = () => closeModal(overlay);
    head.append(title, ctl, refresh, close);
    modal.append(head, body);

    overlay.onmousedown = (e) => { if (e.target === overlay) closeModal(overlay); };
    const onKey = (e) => { if (e.key === 'Escape') closeModal(overlay); };
    overlay._onKey = onKey;
    document.addEventListener('keydown', onKey);

    body.append(el('p', 'muted', 'Loading ' + id + ' ...'));
    document.body.append(overlay);
    await load();
    restart();
}

function renderClientBody(body, overlay, d, reload) {
    const c = d.client;
    const now = d.now;
    const ago = (t) => t ? (now - t) + ' s ago' : '-';
    body.replaceChildren();

    const tbl = el('table', 'kv');
    const sec = (t) => { const r = el('tr', 'kv-sec'); const td = el('td', '', t); td.colSpan = 2; r.append(td); tbl.append(r); };
    const kv = (k, v) => { const r = el('tr'); r.append(el('td', 'kv-k', k), el('td', 'kv-v', (v === null || v === undefined || v === '') ? '-' : String(v))); tbl.append(r); };
    const kvId = (k, id) => {
        const r = el('tr'), v = el('td', 'kv-v');
        if (id) { const s = el('span', 'id-link', id); s.onclick = () => { closeModal(overlay); showClient(id); }; v.append(s); } else { v.textContent = '-'; }
        r.append(el('td', 'kv-k', k), v); tbl.append(r);
    };

    sec('Presence');
    kv('Status', c.online ? 'online' : 'offline');
    kv('IP (last known)', c.ip);
    kv('First seen', fmtTime(c.first_seen));
    kv('Last seen', fmtTime(c.last_seen) + ' (' + ago(c.last_seen) + ')');
    kv('Hellos', c.hello_count);
    kv('Latency', c.latency === null ? '-' : c.latency + ' ms');
    kv('Debug', debugLabel(c));

    sec('1:1 / connection');
    if (c.duel) {
        kv('State', STATE_LABEL[c.duel.state] || c.duel.state);
        kvId('Peer', c.duel.peer);
        kv('Mode', c.duel.mode);
        kv('Updated', c.duel.age + ' s ago (' + (c.duel.live ? 'live' : 'stale') + ')');
        if (c.duel.relay_seen) kv('Last relay', (now - c.duel.relay_seen) + ' s ago');
    } else {
        kv('State', 'not in a duel');
    }
    if (c.matchmaking) {
        if (c.matchmaking.matched_with) kvId('Matched with', c.matchmaking.matched_with);
        else kv('Matchmaking', 'seeking since ' + ago(c.matchmaking.since));
    }

    sec('Relay & scores');
    kv('Relay messages', c.relay_rate ? c.relay_rate.total : 0);
    if (c.relay_rate && c.relay_rate.blocked_until > now) kv('Rate-limited', 'for ' + (c.relay_rate.blocked_until - now) + ' s');
    kv('Friends', c.friends.accepted + ' (' + c.friends.pending + ' pending)');
    kv('Scores', c.scores.count + (c.scores.best !== null ? ', best ' + c.scores.best : ''));
    kv('Mailbox', c.mailbox + ' pending signal(s)');
    if (c.friend_ban_until > now) kv('Friend-banned', 'for ' + (c.friend_ban_until - now) + ' s');

    sec('Config backup');
    if (c.backup) {
        kv('Stored', 'yes');
        kv('When', fmtTime(c.backup.updated) + ' (' + ago(c.backup.updated) + ')');
        kv('Size', fmtBytes(c.backup.bytes));
        kv('Token', c.backup.enrolled ? 'set' : 'reset - client can re-enroll');
        const r = el('tr');
        const v = el('td', 'kv-v');
        // Manual recovery: download the config WITHOUT the token, as the
        // snake-fok-backup.json the game imports directly.
        const dl = el('button', 'small', 'download backup');
        dl.onclick = () => { window.location = API + '?action=vault_export&id=' + c.id; };
        // Clear the token so a client that lost it can re-enroll on its next
        // backup (the data is kept).
        const rst = el('button', 'small', 'reset token');
        rst.onclick = async () => {
            if (!confirm('Reset the backup token for ' + c.id + '?\nIts next backup mints a new token; until then anyone who knows the id could claim it.')) return;
            await api('vault_reset', { method: 'POST', body: form({ id: c.id }) });
            reload();
        };
        v.append(dl, rst);
        r.append(el('td', 'kv-k', 'Recovery'), v);
        tbl.append(r);
    } else {
        kv('Stored', 'no');
    }

    body.append(tbl);
}

// Registered-users live filter (id or name), kept across the manual
// refreshes that follow a debug toggle or a delete.
let usersFilter = '';

// Alerts card state, kept across the card's live refreshes: the open tab,
// the log severity filter, and the last log payload so switching filters is
// instant without a refetch.
let alertsTab = 'alerts';   // 'alerts' | 'logs' | 'perf'
let logFilter = 'all';      // 'all' | 'warn' | 'error'
let lastLog = null;
let lastCaps = null;

function renderAlerts(box, d) {
    box.replaceChildren();
    if (!d.alerts.length) { box.append(el('p', 'muted', 'No alerts.')); return; }
    box.append(el('p', d.unseen ? 'error' : 'muted',
        d.unseen ? d.unseen + ' unseen alert(s)' : 'All alerts seen.'));
    const table = el('table');
    table.append(row(['Time', 'Type', 'Message'], 'th'));
    for (const a of d.alerts) {
        const words = a.message.split(' ');
        const preview = words.length > ALERT_PREVIEW_WORDS
            ? words.slice(0, ALERT_PREVIEW_WORDS).join(' ') + ' ...'
            : a.message;
        const link = el('span', 'msg-link', preview);
        link.title = a.message;
        const msg = el('td');
        msg.append(link);
        const r = el('tr', 'alert-row');
        r.append(el('td', '', fmtTime(a.created)), el('td', '', a.type), msg);
        if (!a.seen) r.classList.add('unseen');
        r.onclick = () => showAlert(a);
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
}

// Severity ladder for the Logs filter: 'all' shows everything, 'warn' shows
// warnings and errors, 'error' shows errors only.
function logKeep(level) {
    if (logFilter === 'all') return true;
    if (logFilter === 'warn') return level !== 'info';
    return level === 'error';
}

function renderLogs(box) {
    box.replaceChildren();
    const d = lastLog || { entries: [], bytes: 0, truncated: false };

    const bar = el('div', 'logbar');
    const chip = (key, label) => {
        const c = el('button', 'chip' + (logFilter === key ? ' active' : ''), label);
        c.onclick = () => { logFilter = key; renderLogs(box); };
        return c;
    };
    bar.append(chip('all', 'All'), chip('warn', 'Warnings'), chip('error', 'Errors'), el('span', 'grow'));
    const clear = el('button', 'small', 'Clear');
    clear.onclick = async () => {
        if (!confirm('Clear the whole server log?')) return;
        await api('log_clear', { method: 'POST' });
        refreshModule('alerts');
    };
    bar.append(clear);
    box.append(bar);

    const shown = d.entries.filter((e) => logKeep(e.level));
    if (!shown.length) {
        box.append(el('p', 'muted', d.entries.length ? 'No entries at this level.' : 'Log is empty.'));
        return;
    }
    const view = el('div', 'logview');
    for (const e of shown) view.append(el('div', 'logline log-' + e.level, e.text));
    box.append(view);
    box.append(el('p', 'muted', 'Showing ' + shown.length + ' of ' + d.entries.length
        + ' recent entries' + (d.truncated ? ', tail of ' + fmtBytes(d.bytes) + ' log' : '')
        + '. Newest first.'));
}

// Host capability assessment. Probed server-side once per release and read
// from the database after that (see src/Caps.php), so opening this tab costs
// nothing; Update forces a fresh assessment.
function renderPerf(box, d) {
    box.replaceChildren();
    const bar = el('div', 'logbar');
    const when = d.checked
        ? 'assessed ' + fmtTime(d.checked) + ' for v' + d.version
        : 'not assessed yet';
    bar.append(el('span', 'muted', when), el('span', 'grow'));
    const upd = el('button', 'small', 'Update');
    upd.onclick = async () => {
        upd.disabled = true;
        upd.textContent = 'checking...';
        const r = await api('caps_refresh', { method: 'POST' });
        lastCaps = r;
        renderPerf(box, r);
    };
    bar.append(upd);
    box.append(bar);

    const table = el('table');
    table.append(row(['', 'Capability', 'Value'], 'th'));
    for (const c of (d.checks || [])) {
        const r = el('tr');
        const dot = el('td');
        dot.append(el('span', 'badge perf-' + c.status, c.status));
        const val = el('td');
        val.append(el('div', '', c.value));
        // The note is why it matters, not decoration: it says what is lost.
        if (c.note) val.append(el('div', 'muted', c.note));
        r.append(dot, el('td', '', c.label), val);
        table.append(r);
    }
    box.append(table);
}

const MODULES = [
    {
        id: 'stats',
        title: 'Statistics',
        async refresh(box) {
            const d = await api('stats');
            box.replaceChildren();
            const grid = el('div', 'statgrid');
            const stat = (label, value, cls) => {
                const s = el('div', 'stat' + (cls ? ' ' + cls : ''));
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
            // Live gauges: totals over the last complete minute.
            const L = d.load_live || { in: 0, out: 0, db_writes: 0 };
            stat('Msgs in/min', L.in, 'live');
            stat('Msgs out/min', L.out, 'live');
            stat('DB writes/min', L.db_writes, 'live');
            box.append(grid);
            box.append(el('p', 'muted', 'Server v' + d.server_version +
                '. Live gauges: last full minute.'));
            // Server clock lives in the page footer, refreshed with the stats.
            const srvtime = document.getElementById('srvtime');
            if (srvtime) srvtime.textContent = ' - ' + fmtTime(d.now);
        },
    },
    {
        id: 'conns',
        title: 'Connections (online)',
        // Own, much faster interval: presence changes fast and a dropped
        // client should clear within a second or two.
        every: 'admin_conns_refresh_secs',
        async refresh(box) {
            const d = await api('conns');
            box.replaceChildren();
            if (!d.conns.length) { box.append(el('p', 'muted', 'No client here.')); return; }
            const table = el('table');
            table.append(row(['ID', 'Name', 'IP', 'Lat', 'Age'], 'th'));
            for (const c of d.conns) {
                const r = el('tr');
                r.classList.add(c.gone ? 'gone' : 'online');
                r.append(idCell(c.id), el('td', '', c.name === null ? '-' : c.name), ipCell(c.ip),
                    el('td', '', c.latency === null ? '-' : c.latency + ' ms'),
                    el('td', '', (d.now - c.last_seen) + ' s' + (c.gone ? ' gone' : '')));
                table.append(r);
            }
            box.append(table);
            sortable(table, 'conns');
        },
    },
    {
        id: 'duels',
        title: '1:1 Duels',
        every: 'admin_duels_refresh_secs',
        async refresh(box) {
            const d = await api('duels');
            box.replaceChildren();
            if (!d.duels.length) { box.append(el('p', 'muted', 'No 1:1 activity.')); return; }
            const table = el('table');
            table.append(row(['Client', 'Name', 'Peer', 'State', 'Mode', 'Lat', 'Msgs', 'Age'], 'th'));
            for (const c of d.duels) {
                const r = el('tr');
                r.classList.add(c.state === 'ended' ? 'gone' : 'online');
                const state = el('td');
                state.append(el('span', 'badge ' + c.state, STATE_LABEL[c.state] || c.state));
                r.append(idCell(c.id), el('td', '', c.name === null ? '-' : c.name),
                    c.peer === null ? el('td', '', '-') : idCell(c.peer));
                r.append(state);
                r.append(el('td', c.mode === 'relay' ? 'error' : '', c.mode === null ? '-' : c.mode));
                r.append(el('td', '', c.latency === null ? '-' : c.latency + ' ms'));
                r.append(el('td', 'muted', c.msgs));
                r.append(el('td', 'muted', (d.now - c.since) + ' s'));
                table.append(r);
            }
            box.append(table);
            sortable(table, 'duels');
            box.append(el('p', 'muted', 'Every phase of a 1:1 - matchmaking, invite, connect, play - '
                + 'and 10 s after it ends. Msgs: relay messages sent. Click a header to sort.'));
        },
    },
    {
        id: 'alerts',
        title: 'Alerts & diagnostics',
        async refresh(box) {
            // The tab bar is built once and kept, so a live refresh never
            // steals the open tab; only the panel below it re-renders.
            let panel = box.querySelector('.tabpanel');
            if (!panel) {
                box.replaceChildren();
                const bar = el('div', 'tabbar');
                const mkTab = (key, label) => {
                    const b = el('button', 'tab', label);
                    b.dataset.tab = key;
                    b.onclick = () => { if (alertsTab !== key) { alertsTab = key; refreshModule('alerts'); } };
                    return b;
                };
                bar.append(mkTab('alerts', 'Alerts'), mkTab('logs', 'Logs'), mkTab('perf', 'Performance'));
                panel = el('div', 'tabpanel');
                box.append(bar, panel);
            }
            for (const b of box.querySelectorAll('.tabbar .tab')) {
                b.classList.toggle('active', b.dataset.tab === alertsTab);
            }
            // The log is fetched ONLY while its tab is open: populated on
            // select, then live-followed by this card's own refresh. The
            // default Alerts tab never pays to read the log file.
            if (alertsTab === 'logs') {
                lastLog = await api('log');
                renderLogs(panel);
            } else if (alertsTab === 'perf') {
                // Read ONCE and keep. The assessment only changes on a new
                // release or an explicit Update, so the card's live refresh
                // must not keep asking for it.
                if (lastCaps === null) {
                    lastCaps = await api('caps');
                }
                renderPerf(panel, lastCaps);
            } else {
                renderAlerts(panel, await api('alerts'));
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
            // Live filter over id and name; the last known IP moved to the
            // per-client details popup (click an id), so it is off the list.
            const search = el('input', 'usearch');
            search.type = 'search';
            search.placeholder = 'Filter by ID or name...';
            search.value = usersFilter;
            box.append(search);
            const table = el('table');
            table.append(row(['ID', 'Name', 'First', 'Last', 'N', 'Lat', 'Debug', ''], 'th'));
            for (const u of d.users) {
                const online = d.now - u.last_seen <= d.online_window;
                const r = el('tr');
                if (online) r.classList.add('online');
                r.append(idCell(u.id), el('td', '', u.name === null ? '-' : u.name),
                    el('td', '', fmtTime(u.first_seen)), el('td', '', fmtTime(u.last_seen)),
                    el('td', '', u.hello_count), el('td', '', u.latency === null ? '-' : u.latency + ' ms'));

                // Debug can be set on an OFFLINE client too: it is a wish
                // stored on the player and applied on its next connect, so
                // it belongs here per registered user, not only per conn.
                const label = debugLabel(u);
                const dbg = el('td', 'debug-cell');
                dbg.append(el('span', 'badge dbg-' + label, label));
                const toggle = el('button', 'small', u.debug ? 'off' : 'on');
                toggle.onclick = async () => {
                    toggle.disabled = true;
                    await api('set_debug', { method: 'POST', body: form({ id: u.id, on: u.debug ? '0' : '1' }) });
                    refreshModule('users');
                };
                dbg.append(toggle);
                r.append(dbg);

                const btn = el('button', 'small', 'delete');
                btn.onclick = async () => {
                    if (!confirm('Delete player ' + u.id + '?')) return;
                    await api('delete_player', { method: 'POST', body: form({ id: u.id }) });
                    refreshModule('users');
                };
                const td = el('td');
                td.append(btn);
                r.append(td);
                r._search = (u.id + ' ' + (u.name || '')).toLowerCase();
                table.append(r);
            }
            box.append(table);
            sortable(table, 'users');
            const applyFilter = () => {
                usersFilter = search.value.trim().toLowerCase();
                for (const r of table.querySelectorAll('tr')) {
                    if (r._search === undefined) continue;
                    r.style.display = (!usersFilter || r._search.includes(usersFilter)) ? '' : 'none';
                }
            };
            search.oninput = applyFilter;
            applyFilter();
            box.append(el('p', 'muted', 'Debug: pending = set, not yet picked up '
                + '(applies on the client next connect); self = the client turned it on itself.'));
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
        id: 'debug',
        title: 'Debug reports',
        async refresh(box) {
            const d = await api('debug_list');
            box.replaceChildren();
            if (!d.datasets.length) { box.append(el('p', 'muted', 'No debug reports.')); return; }

            const cbs = [];
            const master = el('input');
            master.type = 'checkbox';
            master.title = 'Select all';
            const dlSel = iconBtn(ICON.download, 'Download selected');
            const delSel = iconBtn(ICON.trash, 'Delete selected');
            const selected = () => cbs.filter((c) => c.checked).map((c) => c.value);
            const updateBar = () => {
                const n = selected().length;
                dlSel.disabled = delSel.disabled = n === 0;
                master.checked = n === cbs.length;
                master.indeterminate = n > 0 && n < cbs.length;
            };

            const table = el('table', 'seltable');
            const head = el('tr');
            const mth = el('th');
            mth.append(master);
            head.append(mth);
            for (const h of ['PIN', 'Sent', 'Expires', 'Size', '']) head.append(el('th', '', h));
            table.append(head);

            for (const ds of d.datasets) {
                const r = el('tr');
                const cb = el('input');
                cb.type = 'checkbox';
                cb.value = ds.pin;
                cb.onchange = updateBar;
                cbs.push(cb);
                const cbtd = el('td');
                cbtd.append(cb);
                const dl = iconBtn(ICON.download, 'Download');
                dl.onclick = () => downloadPin(ds.pin);
                const actd = el('td');
                actd.append(dl);
                r.append(cbtd, el('td', '', ds.pin), el('td', '', fmtTime(ds.created)),
                    el('td', 'muted', fmtDate(ds.created + d.ttl)), el('td', 'muted', fmtBytes(ds.bytes)), actd);
                table.append(r);
            }

            master.onchange = () => { for (const c of cbs) c.checked = master.checked; updateBar(); };
            // Toggling all must not also sort the (empty) checkbox column.
            master.addEventListener('mousedown', (e) => e.stopPropagation());
            dlSel.onclick = () => selected().forEach((pin, i) => setTimeout(() => downloadPin(pin), i * 200));
            delSel.onclick = async () => {
                const pins = selected();
                if (!pins.length || !confirm('Delete ' + pins.length + ' debug report(s)?')) return;
                await api('debug_delete', { method: 'POST', body: form({ pins: pins.join(',') }) });
                refreshModule('debug');
            };

            const bar = el('div', 'bulk-bar');
            bar.append(dlSel, delSel);
            box.append(bar, table);
            updateBar();
            sortable(table, 'debug');
            mth.classList.remove('sortable');
            box.append(el('p', 'muted', 'A client submits logs + up to two snapshots and reads out '
                + 'the PIN; datasets self-purge after ' + Math.round(d.ttl / 3600) + ' h.'));
        },
    },
    {
        id: 'props',
        title: 'Properties',
        view: 'settings',
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
            prop('PHP', d.php + ' (' + d.sapi + ')');
            const yn = (v) => (v ? 'yes' : 'no');
            prop('opcache', yn(d.opcache));
            prop('APCu', yn(d.apcu) + (d.apcu ? '' : ' - counters stay on the DB writer'));
            prop('Deferred flush', yn(d.deferred_flush)
                + (d.deferred_flush ? '' : ' - bookkeeping runs before the client is answered'));
            // What every request pays before any work; the first one after a
            // deploy also carries the migration, so read it twice.
            prop('DB open', d.db_boot_us + ' us this request');
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

// Click-to-sort for the tables the cards build. The sort a card is showing
// is remembered per card id, so a 1 s refresh reorders the new rows the
// same way instead of throwing the user's choice away.
const sortState = {};

function sortRows(table, col, asc) {
    const rows = Array.from(table.querySelectorAll('tr')).filter((r) => r.querySelector('td'));
    // Sort a column numerically only when the cell STARTS with a number
    // (e.g. "31 ms", "5 s"); ids and IPs then fall back to text order.
    const num = (s) => { const m = s.match(/^-?\d+(?:\.\d+)?(?=\s|$)/); return m ? parseFloat(m[0]) : null; };
    const cell = (r) => (r.children[col] ? r.children[col].textContent.trim() : '');
    rows.sort((a, b) => {
        const x = cell(a), y = cell(b), nx = num(x), ny = num(y);
        const cmp = (nx !== null && ny !== null) ? nx - ny : x.localeCompare(y);
        return asc ? cmp : -cmp;
    });
    for (const r of rows) table.append(r);
}

function markSort(ths, col, asc) {
    ths.forEach((h, i) => {
        h.classList.toggle('sort-asc', i === col && asc);
        h.classList.toggle('sort-desc', i === col && !asc);
    });
}

function sortable(table, id) {
    const ths = table.querySelectorAll('th');
    ths.forEach((th) => th.classList.add('sortable'));
    const st = sortState[id];
    if (st) { markSort(ths, st.col, st.asc); sortRows(table, st.col, st.asc); }
    // Delegate on the card body (which survives the refresh) and sort on
    // mousedown, not click: the live cards rebuild their table every second,
    // so a click - mousedown then mouseup on the SAME element - is often
    // lost when the row is replaced between press and release. mousedown
    // fires on the press alone and cannot be eaten that way.
    const box = table.parentNode;
    if (box && !box._sortBound) {
        box._sortBound = true;
        box.addEventListener('mousedown', (e) => {
            const th = e.target.closest && e.target.closest('th.sortable');
            if (!th) return;
            const tbl = th.closest('table');
            const cols = Array.from(tbl.querySelectorAll('th'));
            const col = cols.indexOf(th);
            if (col < 0) return;
            const cur = sortState[id];
            const asc = !(cur && cur.col === col && cur.asc);
            sortState[id] = { col, asc };
            markSort(cols, col, asc);
            sortRows(tbl, col, asc);
        });
    }
}

const boxes = {};

// Cards on the global interval. Cards with an 'every' of their own are
// not listed; the rest refresh on page load or on their refresh button.
const LIVE = ['stats', 'alerts'];

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

function intervalControl(key, title, slim) {
    const wrap = el('label', 'interval' + (slim ? ' slim' : ''));
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
        const card = el('section', 'card card-' + m.id);
        const head = el('h2');
        head.append(el('span', 'card-title', m.title));
        const btn = el('button', 'small refresh', 'refresh');
        btn.onclick = () => refreshModule(m.id);
        if (m.every) head.append(intervalControl(m.every, m.title + ' refresh interval', true));
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
