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

async function call(query, opts) {
    const res = await fetch(API + '?action=' + query, opts);
    if (res.status === 401) { location.reload(); throw new Error('session expired'); }
    return res.json();
}

// The cards that poll. An admin request costs far more in fixed overhead
// than in the work it does (see admin/api.php), and a dashboard tick brings
// several of these due at once - so the reads of one turn go out as ONE
// request, and two cards asking for the same card share the one answer.
// load_min is the gauge popup's own read and caps is the perf card's one-off:
// neither is on a timer, but both land beside a tick often enough to be worth
// carrying in its request rather than opening a second one.
const POLLED = new Set(['stats', 'conns', 'duels', 'alerts', 'load',
    'load_min', 'caps']);
let due = null;

async function api(action, opts) {
    if (opts || !POLLED.has(action)) {
        return call(action, opts);
    }
    if (due === null) {
        due = new Map();
        setTimeout(sendBatch, 0);
    }
    if (!due.has(action)) {
        due.set(action, []);
    }
    return new Promise((resolve, reject) => due.get(action).push({ resolve, reject }));
}

async function sendBatch() {
    const asked = due;
    due = null;
    try {
        const r = await call('batch&of=' + [...asked.keys()].join(','));
        for (const [card, waiting] of asked) {
            // Each caller gets exactly the payload its own action returns.
            const one = r.ok ? Object.assign({ ok: true }, r[card]) : r;
            for (const w of waiting) w.resolve(one);
        }
    } catch (e) {
        for (const waiting of asked.values()) {
            for (const w of waiting) w.reject(e);
        }
    }
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

// A worst-case stamp: fmtTime's clock with the seconds kept. These rows are
// read against something that happened at a known moment - a duel was
// started, a deploy landed - and a minute is too coarse to line up with it.
function fmtSec(unix) {
    const d = new Date(unix * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '. '
        + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}

// A plain count, kept short enough for a bubble: four digits still fit and
// stay exact, past that the tile has to squeeze and thousands read better.
// The exact figure is in the bubble's tooltip either way.
//
// The optional second argument is the value the UNIT is picked for, so a
// pair of labels on one axis reads in one unit instead of two: a chart whose
// top says "17.9 s" must not have "0 ms" underneath it. A scaled zero is the
// bare digit, because "0.0 s" is the unit said twice.
function fmtNum(n, scale) {
    if (scale !== undefined && n === 0) return '0';
    const s = scale === undefined ? n : scale;
    if (s >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (s >= 10000) return Math.round(n / 1000) + 'k';
    return String(n);
}

function fmtBytes(n, scale) {
    if (scale !== undefined && n === 0) return '0';
    const s = scale === undefined ? n : scale;
    if (s >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (s >= 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
}

// A duration in milliseconds, at the scale it is read at: an hour of worker
// time over the whole server is hours, one script's share of it is seconds.
function fmtMs(ms, scale) {
    if (scale !== undefined && ms === 0) return '0';
    const s = scale === undefined ? ms : scale;
    if (s >= 3600000) return (ms / 3600000).toFixed(1) + ' h';
    if (s >= 60000) return (ms / 60000).toFixed(1) + ' min';
    if (s >= 1000) return (ms / 1000).toFixed(1) + ' s';
    return Math.round(ms) + ' ms';
}

// A queue wait, which lives below a millisecond until the worker pool starts
// to saturate. fmtMs rounds that whole healthy range to a flat "0 ms" and
// would hide the climb this is read for, so under 10 ms it keeps a decimal
// and above it the ordinary scale takes over.
function fmtQms(ms) {
    return ms < 10 ? ms.toFixed(1) + ' ms' : fmtMs(ms);
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

// The ONE scrolling child of a card body or a tab panel (see the height
// model at the top of admin.css): the bars around it keep their natural
// height, this takes what is left and scrolls. Every list builds its scroll
// region through this instead of inventing its own.
function pane(cls, ...kids) {
    const p = el('div', cls ? 'pane ' + cls : 'pane');
    p.append(...kids);
    return p;
}

// The bar that sits above a pane: a note or filter chips on the left, an
// action pushed to the right edge with el('span', 'grow') between them.
function toolbar(...kids) {
    const b = el('div', 'toolbar');
    b.append(...kids);
    return b;
}

// The gauge grid every card with small figures builds: {label, value} plus
// an optional wide (two cells, for a bubble holding a pair of numbers), tip
// (the one line of explanation that would otherwise be prose under the grid)
// and open (what the bubble shows when clicked, with on marking the one that
// is showing it). One definition, so a bubble is the same object wherever it
// shows.
function bubbles(box, items) {
    const grid = el('div', 'statgrid');
    for (const b of items) {
        const s = el('div', 'stat' + (b.wide ? ' wide' : '') + (b.on ? ' on' : ''));
        // A count is shortened to fit the bubble, so the tooltip carries the
        // figure it stands for - otherwise "107k" is all anyone can read.
        const shown = typeof b.value === 'number' ? fmtNum(b.value) : String(b.value);
        const exact = shown === String(b.value) ? '' : String(b.value) + '. ';
        if (b.tip || exact) s.title = exact + (b.tip || '');
        s.append(el('div', 'stat-value', shown), el('div', 'stat-label', b.label));
        if (b.open) {
            s.classList.add('pick');
            s.onclick = b.open;
        }
        grid.append(s);
    }
    box.append(grid);
}

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
// Tournament states reuse the duel badge colours: nothing about them is
// different enough to be worth a palette of its own.
const TSTATE = { open: 'matchmaking', running: 'playing', done: 'ended', abandoned: 'declined' };

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

// A long hex value (an item uid, a state digest): first group shown, the
// whole thing on hover.
function hexCell(v, cls) {
    const td = el('td', cls || '');
    const s = el('span', 'trunc', v.length > 12 ? v.slice(0, 8) + '..' : v);
    s.title = v;
    td.append(s);
    return td;
}

// A ledger party is an 8-hex player id (clickable) or empty / a digest.
function partyCell(v) {
    if (typeof v === 'string' && v.length === 8) return idCell(v);
    return v ? hexCell(v, 'muted') : el('td', 'muted', '-');
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

// Shared modal shell: backdrop + panel + head (a title with a modal-name span)
// + scrollable body, wired to close on the button, click-outside or Escape.
// Callers fill the body, add any extra head controls before the close button,
// then append the overlay to the document.
function makeModal(nameText) {
    const overlay = el('div', 'modal-backdrop');
    const modal = el('div', 'modal');
    const head = el('div', 'modal-head');
    const title = el('div', 'modal-title');
    const name = el('span', 'modal-name', nameText);
    title.append(name);
    const body = el('div', 'modal-body');
    const close = el('button', 'small', 'close');
    close.onclick = () => closeModal(overlay);
    modal.append(head, body);
    overlay.append(modal);
    overlay.onmousedown = (e) => { if (e.target === overlay) closeModal(overlay); };
    const onKey = (e) => { if (e.key === 'Escape') closeModal(overlay); };
    overlay._onKey = onKey;
    document.addEventListener('keydown', onKey);
    return { overlay, modal, head, title, name, body, close };
}

// A minimal read-only popup (no refresh timer) for content that does not fit
// inline, e.g. a full alert message.
function infoModal(titleText, bodyNode) {
    const { overlay, head, title, close, body } = makeModal(titleText);
    head.append(title, close);
    body.append(bodyNode);
    document.body.append(overlay);
}

// A destructive action asks in a popup we own, deliberately NOT through
// confirm(): a browser may suppress a second native dialog raised from the
// same gesture - Chrome offers the operator exactly that - and a suppressed
// confirm() answers cancel, so the button would have done nothing and had
// nothing to say about it. A popup also has room for the whole warning, which
// a note wedged into a toolbar does not.
function confirmModal(titleText, message, confirmLabel, onConfirm) {
    const { overlay, head, title, close, body } = makeModal(titleText);
    close.textContent = 'cancel';
    head.append(title, close);
    body.append(el('p', 'modal-msg', message));
    const foot = el('div', 'modal-foot');
    const go = el('button', 'small', confirmLabel);
    go.onclick = () => { closeModal(overlay); onConfirm(); };
    foot.append(go);
    body.append(foot);
    document.body.append(overlay);
    // Focus cancel, not the destructive button: Escape and Enter must both be
    // the safe answer.
    close.focus();
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
    // The head is built once and stays put; only the body re-renders, so
    // the interval control keeps focus and the popup does not flicker.
    const { overlay, head, title, name, body, close } = makeModal(id);
    title.append(el('span', 'modal-id', id));

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
    head.append(title, ctl, refresh, close);

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

    sec('Gameplay stats (self-reported)');
    const st = c.stats;
    if (st && st.updated) {
        kv('Games', st.games);
        kv('Levels cleared', st.levels + ', furthest ' + st.best_level);
        kv('Deaths', st.deaths);
        kv('Duels', st.duels + ' (' + st.duels_won + ' won)');
        kv('Playtime', st.play_seconds + ' s');
        kv('Updated', fmtTime(st.updated) + ' (' + ago(st.updated) + ')');
    } else {
        kv('Stored', 'none');
    }

    body.append(tbl);
}

// Registered-users live filter (id or name), kept across the manual
// refreshes that follow a debug toggle or a delete.
let usersFilter = '';

// ---------------------------------------------------------- tabbed cards
//
// ONE tab implementation for every tabbed card, so no two tiles can drift
// apart in look or in behaviour. A card declares its tabs as
// [{key, label, render(panel)}] and RETURNS tabs(...) from its refresh; the
// markup is always .tabbar + .tabpanel (styled in admin.css) and the panel
// is always the .pane half of the height model.
//
// The bar is built once and kept, so a live refresh never steals the open
// tab, and only the ACTIVE tab renders: a tab nobody is looking at costs
// nothing, not even its fetch.
const tabState = {};

function tabs(box, id, defs) {
    const open = defs.some((t) => t.key === tabState[id]) ? tabState[id] : defs[0].key;
    tabState[id] = open;
    if (!box.querySelector('.tabbar')) {
        box.replaceChildren();
        const bar = el('div', 'tabbar');
        for (const t of defs) {
            const b = el('button', 'tab', t.label);
            b.dataset.tab = t.key;
            b.onclick = () => {
                if (tabState[id] === t.key) return;
                tabState[id] = t.key;
                refreshModule(id);
            };
            bar.append(b);
        }
        box.append(bar, el('div', 'tabpanel'));
    }
    for (const b of box.querySelectorAll('.tabbar .tab')) {
        b.classList.toggle('active', b.dataset.tab === open);
    }
    return defs.find((t) => t.key === open).render(box.querySelector('.tabpanel'));
}

// Diagnostics state kept across the card's live refreshes: the log severity
// filter, the last log payload so switching filters is instant without a
// refetch, and the capability assessment.
let logFilter = 'all';      // 'all' | 'warn' | 'error'
let lastLog = null;
let lastCaps = null;

function renderAlerts(box, d) {
    box.replaceChildren();
    if (!d.alerts.length) { box.append(el('p', 'muted', 'No alerts.')); return; }
    box.append(el('p', d.unseen ? 'error' : 'muted',
        d.unseen ? d.unseen + ' unseen alert(s)' : 'All alerts seen.'));
    // One line per alert (see admin.css): the message is written out whole
    // and the message column cuts it off, so the list stays scannable by time
    // and type. The click opens the row's full text.
    const table = el('table', 'alerts');
    table.append(row(['Time', 'Type', 'Message'], 'th'));
    for (const a of d.alerts) {
        const link = el('span', 'msg-link', a.message);
        link.title = a.message;
        const msg = el('td', 'msg');
        msg.append(link);
        const r = el('tr', 'alert-row');
        r.append(el('td', '', fmtTime(a.created)), el('td', '', a.type), msg);
        if (!a.seen) r.classList.add('unseen');
        r.onclick = () => showAlert(a);
        table.append(r);
    }
    // The list is the scroll region; the count above and the button below
    // stay put, so "Mark all seen" is reachable without scrolling to it.
    box.append(pane('', table));
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

    const bar = toolbar();
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
    const view = pane('logview');
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
    const bar = toolbar();
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

    const table = el('table', 'wrap');
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
    // The bar above stays put and this scrolls - the .pane half of the one
    // height model (see admin.css), same as the log and the alert list.
    box.append(pane('', table));
}

// The two windows a graph can cover, as the axis reads them. Which one a
// gauge opens in follows the Live tab's selector (see showGaugeCharts).
const AXIS = {
    hour: { start: '-24h', mid: '-12h', aria: 'last 24 hours' },
    min: { start: '-60min', mid: '-30min', aria: 'last 60 minutes' },
};

// One series, drawn to one scale: max and baseline labelled, an area fill
// under the line, the newest point emphasized and repeated in the head. Both
// tick labels are formatted against the MAX, so one axis carries one unit -
// picking a unit per label put "17.9 s" over "0 ms". The colour comes from a
// class so it stays on the palette (see admin.css), and the viewBox carries
// the label gutters so nothing is clipped at any card width.
function chart(box, title, data, fmt, cls, axis) {
    const ax = axis || AXIS.hour;
    const wrap = el('div', 'chartbox ' + cls);
    const head = el('div', 'chart-head');
    head.append(el('span', 'chart-title', title),
        el('span', 'chart-now', fmt(data[data.length - 1])));
    wrap.append(head);

    const W = 240, H = 62, L = 40, R = 4, T = 6, B = 13;
    const max = Math.max(...data, 1);
    const x = (i) => L + (i / (data.length - 1)) * (W - L - R);
    const y = (v) => T + (1 - v / max) * (H - T - B);
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', title + ', ' + ax.aria);
    const add = (tag, attrs, text) => {
        const n = document.createElementNS(NS, tag);
        for (const k in attrs) n.setAttribute(k, String(attrs[k]));
        if (text !== undefined) n.textContent = text;
        svg.append(n);
    };
    for (const [v, dash] of [[max, '2 3'], [0, '']]) {
        add('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), class: 'grid',
            'stroke-dasharray': dash });
        add('text', { x: L - 5, y: y(v) + 3, 'text-anchor': 'end', class: 'tick' },
            fmt(v, max));
    }
    const pts = data.map((v, i) => x(i) + ',' + y(v)).join(' ');
    add('polygon', { points: L + ',' + y(0) + ' ' + pts + ' ' + (W - R) + ',' + y(0), class: 'area' });
    add('polyline', { points: pts, class: 'line' });
    add('circle', { cx: x(data.length - 1), cy: y(data[data.length - 1]), r: 2.2, class: 'dot' });
    for (const [px, lab, anchor] of [[L, ax.start, 'start'],
        [x((data.length - 1) / 2), ax.mid, 'middle'], [W - R, 'now', 'end']]) {
        add('text', { x: px, y: H - 3, 'text-anchor': anchor, class: 'tick' }, lab);
    }
    wrap.append(svg);
    box.append(wrap);
}

// Live: the server's own gauges, over ONE window - the last complete minute
// or the last complete hour, whichever the selector says. One window for the
// whole tile: a tile reading "/min" on one bubble and "/h" on the next leaves
// the conversion to whoever reads it, and the two never line up anyway. Both
// windows are complete ones, so a figure is a whole window every time it is
// read instead of a number climbing from zero.
//
// Levels - what the server IS holding rather than what passed through it -
// carry no window and read the same either way.
let liveWindow = 'min';

// One series off the counter buckets, hour or minute: the caller passes the
// map and the keys of the window it wants. A counted total is a true zero in
// a bucket that counted none; a LEVEL is not - it was simply not sampled -
// so the last known reading carries forward instead (see
// Counters::sampleGauges).
function series(buckets, keys, pick, level) {
    let last = 0;
    return keys.map((k) => {
        const v = pick(buckets[k] || {});
        if (v === null) return level ? last : 0;
        last = v;
        return v;
    });
}

// One named metric, absent meaning "not recorded" rather than zero.
const one = (name) => (m) => (m[name] === undefined ? null : m[name]);

// The queue, in milliseconds, off the two shapes Util::noteQueue books it as:
// a sum with a count to divide it by, and a per-bucket maximum. Absent means
// the bucket recorded no wait at all - not a wait of zero - so the graph
// leaves a true zero there rather than carrying a reading forward.
const qMeanMs = (m) => (m['n:q_n'] === undefined ? null
    : (m['n:q_n'] > 0 ? (m['n:q_us'] || 0) / m['n:q_n'] / 1000 : 0));
const qMaxMs = (m) => (m['x:q_us'] === undefined ? null : m['x:q_us'] / 1000);

// Summed over the endpoints, by the same metric shapes AdminData::window
// sorts by: '' is the request counts, '.ms' / '.cpu' / '.db' what they cost.
// A namespaced metric is not an endpoint, and req_min is the requests counted
// a second time, as a total.
const total = (suffix) => (m) => {
    let n = 0;
    for (const k in m) {
        if (k.indexOf(':') >= 0 || k.indexOf('mint_') === 0 || k === 'req_min') continue;
        const dot = k.lastIndexOf('.');
        if ((dot < 0 ? '' : k.slice(dot)) === suffix) n += m[k];
    }
    return n;
};

// The worst queue waits on record, under the graphs of the same gauge (see
// Counters::worst). A graph says the pool stalled; this says which requests
// were standing in it, which is the half the graph cannot show.
//
// Not a .pane: one of those inside a modal takes the body's scroll for
// itself and squeezes the charts above it (see admin.css). The popup
// scrolls as a whole instead.
function worstQueue(rows) {
    const box = el('div');
    box.append(el('div', 'subhead', 'Worst waits, last 24 h'));
    if (!rows || !rows.length) {
        box.append(el('div', 'muted', 'Nothing has waited as long as a '
            + 'millisecond. Shorter waits are not recorded - there is nothing '
            + 'in them to diagnose.'));
        return box;
    }
    const t = el('table');
    // The depth column holds one digit, so its header is one letter and the
    // explanation moves into a tooltip: spelled out, the word was setting the
    // width of the narrowest column in the table.
    const th = (label, cls, tip) => {
        const h = el('th', cls, label);
        if (tip) h.title = tip;
        return h;
    };
    const hr = el('tr');
    hr.append(th('When'), th('Waited'),
        th('D', 'tight', 'Depth: how many requests this player already had open '
            + 'when this one started. A floor, not a count - a sibling that '
            + 'finished during the wait is not in it.'),
        th('Worker'), th('Script'), th('Player'), th('Address'));
    t.append(hr);
    for (const r of rows) {
        const tr = el('tr');
        tr.append(el('td', '', fmtSec(r.t)));
        tr.append(el('td', 'num', fmtQms(r.v / 1000)));
        // How many requests that same player had open when this one STARTED
        // (Util::noteCaller). It is what separates one client bursting from
        // several clients arriving, which is the whole question a wait
        // raises. Read it as a floor, not a count: the waiting happened
        // before PHP ran, so a sibling that finished during the wait is not
        // in it, and a genuinely stacked request can still read 1. A dash is
        // a row recorded before this was kept.
        tr.append(r.d ? el('td', 'num tight', String(r.d)) : el('td', 'muted tight', '-'));
        // A worker PHP had just started pays for its own startup before it
        // can answer, and that lands here looking exactly like a busy pool
        // (see Util::claimWorker). "new" on every row means the pool keeps
        // going cold, not that it is short of workers.
        tr.append(r.w ? el('td', '', 'new') : el('td', 'muted', 'reused'));
        tr.append(el('td', '', r.s || '-'));
        // Only the endpoints that take an id in the query string can name a
        // player here; a POST is identified by its address (Util::queueWho).
        tr.append(r.id ? idCell(r.id) : el('td', 'muted', '-'));
        tr.append(ipCell(r.ip));
        t.append(tr);
    }
    box.append(t);
    return box;
}

// A gauge's last 24 h, in the same overlay a player's details open in.
// Under the grid the graphs had to share the tile's height budget with the
// bubbles, which on a narrow screen left them scrolled out of sight.
async function showGaugeCharts(gauge) {
    // Levels are sampled once an hour and only ever written to an hour
    // bucket (see Counters::sampleGauges), so a gauge that reads one keeps
    // its 24 h graph in either window - sixty minute buckets it was never
    // written to would draw a flat zero. Everything else follows the
    // selector: a "/min" figure opens a per-minute history.
    const byMin = liveWindow === 'min' && !gauge.charts.every((c) => c[4]);
    const ax = byMin ? AXIS.min : AXIS.hour;
    const name = gauge.label.replace(/\/(min|h)$/, '');
    const { overlay, head, title, close, body } = makeModal(name);
    head.append(title, close);
    body.append(el('div', 'muted', 'Loading the ' + ax.aria + '.'));
    document.body.append(overlay);
    let hist;
    try {
        hist = await api(byMin ? 'load_min' : 'load');
    } catch (e) {
        body.replaceChildren(el('div', 'error', 'The history could not be read.'));
        return;
    }
    // The operator may have closed it while the history was in flight.
    if (!overlay.isConnected) return;
    const charts = el('div', 'charts');
    const keys = byMin ? minuteKeys(hist.now) : hourKeys(hist.now);
    const buckets = (byMin ? hist.minutes : hist.hours) || {};
    for (const [t, cls, pick, fmt, level, reading] of gauge.charts) {
        const data = series(buckets, keys, pick, level);
        // A level is sampled once an hour (see Counters::sampleGauges), so
        // the newest sample the history holds can be most of an hour old and
        // the graph would end on a figure the bubble beside it disagrees
        // with. The bubble is right - it is read on the spot - so the last
        // point is that reading, and the axis label "now" is true.
        if (level && reading !== undefined) data[data.length - 1] = reading;
        // A level reads the same however long the bucket was, and so does a
        // per-request figure - it is an average or a worst case OVER the
        // bucket, not an amount OF it. Only a true total carries the window
        // in its title and only a true total is scaled pro rata: a mean queue
        // wait multiplied up because the hour is half over would be an
        // invention.
        const flat = level || gauge.perRequest === true;
        chart(charts, flat ? t : t + (byMin ? '/min' : '/h'),
            (flat || byMin) ? data : partHour(data, hist.now), fmt, cls, ax);
    }
    body.replaceChildren(charts);
    if (gauge.extra) body.append(gauge.extra());
}

function renderServerLive(box, d) {
    box.replaceChildren();
    const w = (d.live || {})[liveWindow]
        || { stamp: '--', in: 0, out: 0, db_writes: 0, wall_ms: 0, cpu_ms: 0, db: 0, top: null,
            q_mean_us: 0, q_max_us: 0 };
    const m = d.apcu_mem || { used: 0, total: 0 };
    const per = liveWindow === 'min' ? '/min' : '/h';
    const win = (liveWindow === 'min' ? 'Last full minute (' : 'Last full hour (')
        + w.stamp + ' UTC), over all endpoints. ';
    const now = 'Right now. ';
    // A level is only ever sampled hourly, so its graph is the day either
    // way; a total's graph follows the window (see showGaugeCharts).
    const day = 'Click for the last 24 h.';
    const graph = liveWindow === 'min' ? 'Click for the last 60 min.' : day;

    // The window is a choice between two, so it is one switch with the two
    // named either side of it (see admin.css .switchrow) - the chip row it
    // replaces was tall enough to cut the last bubble off the card.
    const bar = toolbar();
    bar.classList.add('switchrow');
    const pick = (key) => () => { liveWindow = key; renderServerLive(box, d); };
    const sw = el('button', 'switch');
    sw.setAttribute('role', 'switch');
    sw.setAttribute('aria-checked', liveWindow === 'hour' ? 'true' : 'false');
    sw.setAttribute('aria-label', 'Read the gauges per hour instead of per minute');
    sw.append(el('span', 'knob'));
    sw.onclick = pick(liveWindow === 'min' ? 'hour' : 'min');
    const left = el('span', 'swname' + (liveWindow === 'min' ? ' on' : ''), 'Per minute');
    const right = el('span', 'swname' + (liveWindow === 'hour' ? ' on' : ''), 'Per hour');
    left.onclick = pick('min');
    right.onclick = pick('hour');
    bar.append(left, sw, right);
    box.append(bar);

    // Label, value and tip as shown; charts is what the click opens, each
    // entry [title, colour class, series, formatter, level?, reading now?].
    // A LEVEL carries that last field: its newest sample can be most of an
    // hour old, and the graph ends where the bubble is read (see
    // showGaugeCharts).
    const gauges = [
        // Queue first: it is the only gauge here that says whether the server
        // is KEEPING UP, and everything below it says how hard it is working.
        { label: 'Queue mean | worst', wide: true, perRequest: true,
            value: fmtQms(w.q_mean_us / 1000) + ' | ' + fmtQms(w.q_max_us / 1000),
            tip: win + 'How long a request waited for a free PHP worker before '
                + 'it started. This should be close to zero: the pool has about '
                + 'twenty workers, and once they are all busy new requests queue '
                + 'behind them. Tens of milliseconds is the pool running warm, '
                + 'hundreds is saturation. ' + graph
                + ' The worst cases of the last 24 h are listed under the graphs. '
                + 'Requests from this dashboard are left out of all of it: the '
                + 'dashboard polls only while somebody is watching this gauge, and '
                + 'it would otherwise be measuring itself.',
            charts: [['Queue wait, mean', 'chart-wall', qMeanMs, fmtQms],
                ['Queue wait, worst', 'chart-cpu', qMaxMs, fmtQms]],
            extra: () => worstQueue(d.q_worst) },
        { label: 'Msgs in | out' + per, value: fmtNum(w.in) + ' | ' + fmtNum(w.out),
            tip: win + 'Requests answered, and hub messages handed out. ' + graph,
            charts: [['Requests', 'chart-req', total(''), fmtNum],
                ['Messages out', 'chart-req', one('n:msg_out'), fmtNum]] },
        { label: 'DB writes' + per, value: w.db_writes,
            tip: win + 'Writes through the single SQLite writer. ' + graph,
            charts: [['DB writes', 'chart-db', one('n:db_w'), fmtNum]] },
        { label: 'DB entries', value: d.db_rows,
            tip: now + 'Rows over every table. ' + day,
            charts: [['DB entries', 'chart-req', one('g:db_rows'), fmtNum, true,
                d.db_rows]] },
        { label: 'DB size', value: fmtBytes(d.db_size),
            tip: now + 'The database file on disk. ' + day,
            charts: [['DB size', 'chart-req', one('g:db_size'), fmtBytes, true,
                d.db_size]] },
        { label: 'APCu memory', value: m.total === 0 ? '-' : fmtBytes(m.used),
            tip: m.total === 0 ? 'Shared memory is not usable on this host'
                : 'Shared memory in use of ' + fmtBytes(m.total) + ' ('
                    + Math.round(m.used / m.total * 100) + '%). Signaling, relayed'
                    + ' duels and tournaments live here and fail when it fills. ' + day,
            charts: m.total === 0 ? null
                : [['APCu memory', 'chart-cpu', one('g:apcu'), fmtBytes, true,
                    m.used]] },
        { label: 'PHP time' + per, value: fmtMs(w.wall_ms),
            tip: win + 'Worker time held, the slot other clients queue for. ' + graph,
            charts: [['PHP worker time', 'chart-wall', total('.ms'), fmtMs]] },
        { label: 'CPU time' + per, value: fmtMs(w.cpu_ms),
            tip: win + 'Processor time actually burned. ' + graph,
            charts: [['CPU time', 'chart-cpu', total('.cpu'), fmtMs]] },
        { label: 'DB calls' + per, value: w.db,
            tip: win + 'Queries the endpoints caused - opening the connection is '
                + 'not one of them (see Load::openDone). ' + graph,
            charts: [['DB queries', 'chart-db', total('.db'), fmtNum]] },
        { label: 'Busiest script', value: w.top === null ? '-' : w.top, wide: true,
            tip: win + 'Held the most worker time. ' + (w.top === null ? '' : graph),
            charts: w.top === null ? null
                : [[w.top + ' requests', 'chart-req', one(w.top), fmtNum],
                    [w.top + ' worker time', 'chart-wall', one(w.top + '.ms'), fmtMs]] },
    ];

    bubbles(box, gauges.map((g) => ({
        label: g.label, value: g.value, tip: g.tip, wide: g.wide,
        open: g.charts === null ? null : () => showGaugeCharts(g),
    })));
}

// A UTC instant as the stamp the counters are keyed by: ten digits for an
// hour bucket, twelve for a minute one (see Counters).
function utcKey(unix, toMinute) {
    const p = (n) => String(n).padStart(2, '0');
    const t = new Date(unix * 1000);
    return String(t.getUTCFullYear()) + p(t.getUTCMonth() + 1) + p(t.getUTCDate())
        + p(t.getUTCHours()) + (toMinute ? p(t.getUTCMinutes()) : '');
}

// The 24 hour buckets ending with the running one, as the UTC stamps the
// counters are keyed by. Built from the axis rather than from the data, so
// an hour with no traffic is a zero in its own place instead of a gap that
// shifts the line.
function hourKeys(now) {
    // The running hour is only in the table once a minute of it has closed
    // and been folded into it (see Counters::flushMinute). In the first
    // minute of an hour there is nothing in it to draw and nothing to scale,
    // so the graph ends on the hour before (see partHour).
    const last = Math.floor((now % 3600) / 60) >= 1 ? 0 : 1;
    const keys = [];
    for (let i = 23; i >= last; i--) {
        keys.push(utcKey(now - i * 3600, false));
    }
    return keys;
}

// A totals series over hourKeys, with its running hour read pro rata. That
// bucket holds only the minutes of the hour that have closed so far, so
// plotted raw beside 23 whole hours it is a nosedive at the right edge - a
// fraction of an hour drawn as if it were a whole one. Scaled up, the last
// point is the rate the hour is running at, which is what every other point
// on the graph already is.
//
// Totals only: a LEVEL is what the server is holding right now, not
// something that accumulates over its bucket, so there is no fraction to
// undo. The minute window needs none of this either - it ends on the last
// CLOSED minute (see minuteKeys).
function partHour(data, now) {
    const done = Math.floor((now % 3600) / 60);
    if (done < 1) return data;
    const out = data.slice();
    out[out.length - 1] = Math.round(out[out.length - 1] * 60 / done);
    return out;
}

// The 60 minute buckets ending with the last complete one, as the UTC
// stamps Counters::flushMinute keys them by. Built from the axis for the
// same reason as hourKeys, and it ends one minute back because the running
// minute is still being counted - drawn, it would be a dip to nearly zero
// at the right edge of every graph.
function minuteKeys(now) {
    const keys = [];
    for (let i = 60; i >= 1; i--) {
        keys.push(utcKey(now - i * 60, true));
    }
    return keys;
}

// One row per endpoint out of one window's counters: the requests it served
// (the metric as counted) and what they cost - the same metric with .ms,
// .cpu and .db, written by Counters::cost.
function scriptRows(counts) {
    const by = {};
    for (const metric in counts) {
        // "n:" totals, "g:" levels and "x:" maxima are the server's own
        // gauges, not endpoints that served anything (see Counters).
        if (metric.indexOf(':') >= 0) continue;
        const dot = metric.lastIndexOf('.');
        const name = dot < 0 ? metric : metric.slice(0, dot);
        const key = dot < 0 ? 'req' : metric.slice(dot + 1);
        if (key !== 'req' && key !== 'ms' && key !== 'cpu' && key !== 'db') continue;
        const s = by[name] || (by[name] = { name: name, req: 0, ms: 0, cpu: 0, db: 0 });
        s[key] += counts[metric];
    }
    // Ranked by worker time held: on a server whose ceiling is its worker
    // pool, that is the cost deciding whether anyone else gets served.
    return Object.values(by).sort((a, b) => b.ms - a.ms);
}

// The three windows the table can be read over, all three already in the one
// payload (see AdminData::hours) so switching costs no request. "Total" is
// honest about its horizon: it is everything the counters still hold, and
// hour buckets are pruned at 30 days.
const SCRIPT_WINDOWS = {
    total: ['Total', 'Everything the counters still hold - hour buckets are kept for 30 days'],
    hour: ['Hour', 'The last complete UTC hour'],
    min: ['Minute', 'The last complete UTC minute'],
};

// The counted metrics of the selected window, in the one shape scriptRows
// reads. The last COMPLETE hour, not the running one: a partial hour would
// read as a slump that is only the clock.
function scriptCounts(d) {
    if (scriptWindow === 'hour') {
        return (d.hours || {})[utcKey(d.now - 3600, false)] || {};
    }
    if (scriptWindow === 'min') {
        return d.minute || {};
    }
    return d.totals || {};
}

// Per script: the ranking, and one script's 24 h graphs behind a click. The
// choice is kept in the module so the card's live refresh redraws the same
// view instead of throwing the operator back to the list.
let pickedScript = null;
let scriptWindow = 'total';   // which of SCRIPT_WINDOWS the table is read over
// What the last Clear statistics attempt did, kept on screen until something
// else happens. It lives here rather than in the DOM because the card redraws
// itself on its own interval and would wipe a note held in the card.
let clearNote = '';

function renderScripts(box, d) {
    box.replaceChildren();
    const rows = scriptRows(scriptCounts(d));
    // Judged against the whole history, not the selected window: the detail
    // view below is always the 24 h series, so a script that merely served
    // nothing this minute must not throw you out of it.
    if (pickedScript !== null && !(pickedScript in (d.totals || {}))) {
        pickedScript = null;   // it fell out of the history entirely
    }
    if (pickedScript !== null) {
        const back = el('button', 'small', 'All scripts');
        back.onclick = () => { pickedScript = null; renderScripts(box, d); };
        box.append(toolbar(el('span', 'grow'), back));
        const view = pane('');
        const head = el('div', 'detail-head');
        head.append(el('span', 'detail-name', pickedScript),
            el('span', 'detail-sub', 'per UTC hour, last 24 h'));
        view.append(head);
        const keys = hourKeys(d.now);
        const at = (suffix) => partHour(
            keys.map((k) => (d.hours[k] || {})[pickedScript + suffix] || 0), d.now);
        const charts = el('div', 'charts');
        chart(charts, 'Requests', at(''), String, 'chart-req');
        chart(charts, 'PHP worker time', at('.ms'), fmtMs, 'chart-wall');
        chart(charts, 'CPU time', at('.cpu'), fmtMs, 'chart-cpu');
        chart(charts, 'DB queries', at('.db'), String, 'chart-db');
        view.append(charts);
        box.append(view);
        return;
    }
    const bar = toolbar();
    const chip = (key) => {
        const c = el('button', 'chip' + (scriptWindow === key ? ' active' : ''),
            SCRIPT_WINDOWS[key][0]);
        c.title = SCRIPT_WINDOWS[key][1];
        c.onclick = () => {
            scriptWindow = key;
            clearNote = '';
            renderScripts(box, d);
        };
        return c;
    };
    bar.append(chip('total'), chip('hour'), chip('min'), el('span', 'grow'));
    const clear = el('button', 'small', 'Clear statistics');
    clear.title = 'Erases every per-script figure and every graph on this card, for all '
        + 'three windows. Players, scores and the item registry are not touched.';
    clear.onclick = () => confirmModal('Clear statistics',
        'This erases every per-script figure and every graph on this card, for all '
        + 'three windows, and there is no undo. Players, scores and the item '
        + 'registry are not touched.',
        'Erase the traffic history', async () => {
            clearNote = 'Clearing...';
            renderScripts(box, d);
            try {
                const r = await api('clear_stats', { method: 'POST' });
                clearNote = r.ok
                    ? 'Cleared ' + r.rows + ' stored rows and ' + r.keys + ' buffered counters.'
                    : 'NOT cleared: ' + (r.error || 'the server refused');
            } catch (e) {
                clearNote = 'NOT cleared: ' + e.message;
            }
            pickedScript = null;
            refreshModule('perf');
            refreshModule('alerts');
        });
    bar.append(clear);
    box.append(bar);
    // The outcome is a whole sentence, so it gets a line of its own. As a flex
    // item in the toolbar it was squeezed between the window chips and the
    // button, broke to one word per line and pushed the button out of the card.
    if (clearNote) {
        box.append(el('p', 'barnote' + (clearNote.startsWith('NOT ') ? ' error' : ' muted'),
            clearNote));
    }
    if (!rows.length) {
        box.append(el('p', 'muted', scriptWindow === 'total'
            ? 'No traffic recorded yet.' : 'Nothing was served in that window.'));
        return;
    }
    const t = el('table');
    const th = (label, cls, tip) => {
        const h = el('th', cls, label);
        h.title = tip;
        return h;
    };
    const hr = el('tr');
    hr.append(th('Script', '', 'The counter every request to this script is booked under'),
        th('Req', 'num', 'Requests served in the selected window'),
        th('PHP', 'num', 'Worker time held - the slot other clients queue for'),
        th('CPU', 'num', 'Processor time actually burned; a parked long poll holds a '
            + 'worker without burning any'),
        th('DB', 'num', 'Database queries caused'));
    t.append(hr);
    for (const s of rows) {
        const r = row([s.name, s.req, fmtMs(s.ms), fmtMs(s.cpu), s.db]);
        for (let i = 1; i < 5; i++) r.children[i].className = 'num';
        r.className = 'pick';
        r.children[0].classList.add('id-link');
        r.onclick = () => { pickedScript = s.name; renderScripts(box, d); };
        t.append(r);
    }
    box.append(pane('', t));
}

// Load: requests per UTC hour, the plain history behind the graphs.
function renderLoad(box, d) {
    box.replaceChildren();
    box.append(toolbar(el('span', 'muted', 'Requests per hour, UTC, last 24 h.')));
    const buckets = Object.keys(d.hours).sort();
    if (!buckets.length) {
        box.append(el('p', 'muted', 'No traffic recorded yet.'));
        return;
    }
    const table = el('table');
    table.append(row(['Hour', 'hello', 'score', 'signal'], 'th'));
    for (const b of buckets) {
        const m = d.hours[b];
        table.append(row([
            b.slice(6, 8) + '.' + b.slice(4, 6) + '. ' + b.slice(8) + 'h',
            m.hello || 0, m.score_submit || 0, m.signal || 0,
        ]));
    }
    box.append(pane('', table));
}

// ---- Item registry, over its two tabs ---------------------------------
// Status: what the registry HOLDS - the counters, the chain verify, and the
// two exception lists (a frozen instance, a player whose claims keep being
// disputed). The ledger itself is the other tab.
function renderItemStatus(box, d) {
    box.replaceChildren();

    bubbles(box, [
        { label: 'Items', value: d.items_total },
        { label: 'Frozen', value: d.items_frozen },
        { label: 'Open matches', value: d.matches_open },
        // Two cells wide: the only bubble here holding a pair of numbers.
        { label: 'Ledger rows', value: fmtNum(d.ledger_rows) + ' / ' + fmtNum(d.ledger_max), wide: true },
    ]);

    // Chain-verify: walks the hash chain from the newest checkpoint forward
    // and reports whether it is intact. A deliberate click.
    const line = el('span', 'muted', 'Not verified this session.');
    const vbtn = el('button', 'small', 'Verify chain');
    vbtn.onclick = async () => {
        vbtn.disabled = true;
        line.replaceChildren(el('span', 'muted', 'verifying...'));
        try {
            const v = (await api('items_verify', { method: 'POST' })).verify;
            line.replaceChildren();
            if (v.ok) {
                line.append(el('span', 'badge perf-good', 'intact'),
                    el('span', 'muted', ' ' + v.checked + ' row(s) from n=' + v.from + '.'));
            } else {
                line.append(el('span', 'badge perf-bad', 'BROKEN'),
                    el('span', 'error', ' at n=' + v.break + ' (from n=' + v.from + ').'));
            }
        } catch (e) {
            line.replaceChildren(el('span', 'error', 'Verify failed: ' + e.message));
        }
        vbtn.disabled = false;
    };
    box.append(toolbar(vbtn, ' ', line));

    const view = pane('');
    if (d.frozen.length) {
        view.append(el('h3', 'subhead', 'Frozen items (' + d.frozen.length + ')'));
        const t = el('table');
        t.append(row(['UID', 'Item', 'Owner', 'Seq'], 'th'));
        for (const f of d.frozen) {
            const r = el('tr');
            r.classList.add('gone');
            r.append(hexCell(f.uid, 'muted'), el('td', '', f.item_id),
                idCell(f.owner), el('td', 'muted', f.seq));
            t.append(r);
        }
        view.append(t);
    }

    if (d.disputed.length) {
        view.append(el('h3', 'subhead', 'Disputed claims by player'));
        const t = el('table');
        t.append(row(['ID', 'Name', 'OK', 'Untagged', 'Disputed'], 'th'));
        for (const p of d.disputed) {
            const r = el('tr');
            r.append(idCell(p.id), el('td', '', p.name === null ? '-' : p.name),
                el('td', 'muted', p.ok), el('td', 'muted', p.untagged),
                el('td', 'error', p.disputed));
            t.append(r);
        }
        view.append(t);
        sortable(t, 'items_disputed');
    }

    if (!d.frozen.length && !d.disputed.length) {
        view.append(el('p', 'muted', 'Nothing frozen, no disputed claims.'));
    }
    view.append(el('p', 'muted', 'A frozen item is a claim the ladder judged as '
        + 'tampering. Match secrets not shown.'));
    box.append(view);
}

// Ledger: the audit trail itself, newest first.
function renderItemLedger(box, d) {
    box.replaceChildren();
    if (!d.recent.length) {
        box.append(el('p', 'muted', 'No item activity yet.'));
        return;
    }
    const t = el('table');
    t.append(row(['n', 'Kind', 'UID', 'From', 'To', 'Tick', 'When'], 'th'));
    for (const e of d.recent) {
        const r = el('tr');
        r.append(el('td', 'muted', e.n), el('td', '', e.kind), hexCell(e.uid, 'muted'),
            partyCell(e.from), partyCell(e.to), el('td', 'muted', e.tick),
            el('td', 'muted', fmtTime(Math.floor(e.at / 1000))));
        t.append(r);
    }
    box.append(pane('', t));
    box.append(el('p', 'muted', 'The ledger is audit only and hash-chained - Verify chain on the '
        + 'Status tab walks it. Newest first.'));
}

// ---- Players, over its two tabs ---------------------------------------
// Registered: every player the server knows, filtered live by id or
// name. Top 100 is the global score table, the other end of the same
// population - one card, two tabs, one fetch each and only while open.
function renderUsers(box, d) {
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
            refreshModule('players');
        };
        dbg.append(toggle);
        r.append(dbg);

        const btn = el('button', 'small', 'delete');
        btn.onclick = async () => {
            if (!confirm('Delete player ' + u.id + '?')) return;
            await api('delete_player', { method: 'POST', body: form({ id: u.id }) });
            refreshModule('players');
        };
        const td = el('td');
        td.append(btn);
        r.append(td);
        r._search = (u.id + ' ' + (u.name || '')).toLowerCase();
        table.append(r);
    }
    // The filter field above must not scroll away from the list it
    // filters, so the list is the .pane and the field stays put.
    const view = el('div', 'pane');
    view.append(table);
    box.append(view);
    sortable(table, 'users');
    const applyFilter = () => {
        usersFilter = search.value.trim().toLowerCase();
        for (const r of table.querySelectorAll('tr')) {
            if (r._search === undefined) continue;
            r.classList.toggle('hidden', !!usersFilter && !r._search.includes(usersFilter));
        }
    };
    search.oninput = applyFilter;
    applyFilter();
    const legend = el('p', 'muted', 'Debug: pending = set, applies on client connect;');
    legend.append(el('br'), ' self = the client turned it on.');
    box.append(legend);
}

function renderScores(box, d) {
    box.replaceChildren();
    if (!d.scores.length) { box.append(el('p', 'muted', 'No scores yet.')); return; }
    const table = el('table');
    table.append(row(['#', 'Name', 'Score', 'Diff', 'Lvl', 'Player', 'Valid', 'Date', ''], 'th'));
    for (const s of d.scores) {
        const diff = ['E', 'N', 'H'][s.diff] || 'N';
        const r = row([s.rank, s.name, s.score, diff, s.level, s.player_id,
            s.validated ? 'yes' : '-', s.date]);
        if (s.completed) {
            const lvl = r.children[4];
            lvl.append(' ');
            const star = el('span', 'win', '\u2605');
            star.title = 'Completed the final level';
            lvl.append(star);
        }
        const btn = el('button', 'small', 'delete');
        btn.onclick = async () => {
            if (!confirm('Delete score by ' + s.name + '?')) return;
            await api('delete_score', { method: 'POST', body: form({ id: s.id }) });
            refreshModule('players');
        };
        const td = el('td');
        td.append(btn);
        r.append(td);
        table.append(r);
    }
    box.append(table);
}

const MODULES = [
    {
        id: 'stats',
        title: 'Game Statistics',
        // Own fast interval like Connections: the online counts (and the
        // footer server clock) change second to second. What the SERVER is
        // doing has its own card - this one is about the game.
        every: 'admin_stats_refresh_secs',
        async refresh(box) {
            const d = await api('stats');
            box.replaceChildren();
            bubbles(box, [
                { label: 'Users online', value: d.counts.online },
                { label: 'Online v4 | v6', value: d.families.v4 + ' | ' + d.families.v6,
                    tip: 'Online clients by the address family their last request came in over.' },
                { label: 'Playing 1:1', value: d.counts.playing },
                { label: 'Tournaments', value: d.tourneys },
                { label: 'Users registered', value: d.counts.registered },
                // A game reading, not a server one: it says how many duels
                // failed to find a peer-to-peer path, which is about the
                // players' networks. Its history is a sampled LEVEL, so the
                // popup is the same 24 h graph whichever window the server
                // card happens to be set to (see showGaugeCharts).
                { label: 'Relaying', value: d.relaying,
                    tip: 'Right now. Duels whose game messages pass through the '
                        + 'server instead of going peer to peer. Click for the last 24 h.',
                    open: () => showGaugeCharts({
                        label: 'Relaying',
                        charts: [['Relayed duels', 'chart-req', one('g:relaying'),
                            fmtNum, true, d.relaying]],
                    }) },
                { label: 'Friendships active | pending',
                    value: fmtNum(d.friendships) + ' | ' + fmtNum(d.friendships_pending), wide: true },
                { label: 'Scores stored', value: d.scores_total },
                { label: 'Items owned', value: d.items_total },
                { label: 'Item transfers', value: d.item_transfers },
            ]);
            box.append(el('p', 'muted', 'Server v' + d.server_version + '.'));
            // Server clock lives in the page footer, refreshed with the stats.
            const srvtime = document.getElementById('srvtime');
            if (srvtime) srvtime.textContent = ' - ' + fmtTime(d.now);
        },
    },
    {
        id: 'players',
        title: 'Players',
        refresh(box) {
            return tabs(box, 'players', [
                {
                    key: 'users',
                    label: 'Registered users',
                    render: async (p) => renderUsers(p, await api('users')),
                },
                {
                    key: 'scores',
                    label: 'Global top 100',
                    render: async (p) => renderScores(p, await api('scores')),
                },
            ]);
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
        title: 'Matches',
        every: 'admin_duels_refresh_secs',
        async refresh(box) {
            const d = await api('duels');
            box.replaceChildren();
            box.append(el('h3', 'subhead', '1:1 duels'));
            if (!d.duels.length) box.append(el('p', 'muted', 'No 1:1 activity.'));
            else {
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
            }
            box.append(el('h3', 'subhead', 'Tournaments'));
            const ts = d.tourneys || [];
            if (!ts.length) box.append(el('p', 'muted', 'No tournaments running.'));
            else {
                const table = el('table');
                table.append(row(['Host', 'Name', 'Code', 'State', 'Round', 'Players',
                    'Matches', 'Stakes', 'Age'], 'th'));
                for (const t of ts) {
                    const r = el('tr');
                    r.classList.add(t.state === 'running' || t.state === 'open' ? 'online' : 'gone');
                    const state = el('td');
                    state.append(el('span', 'badge ' + (TSTATE[t.state] || t.state),
                        t.gated ? 'break' : t.state));
                    r.append(idCell(t.host), el('td', '', t.host_name === null ? '-' : t.host_name),
                        el('td', '', t.code));
                    r.append(state);
                    r.append(el('td', '', t.round || '-'));
                    r.append(el('td', '', t.players));
                    r.append(el('td', 'muted', t.nodes ? t.done + '/' + t.nodes : '-'));
                    r.append(el('td', 'muted', t.stakes ? 'yes' : 'no'));
                    r.append(el('td', 'muted', (d.now - t.since) + ' s'));
                    table.append(r);
                }
                box.append(table);
                sortable(table, 'tourneys');
            }
        },
    },
    {
        id: 'items',
        title: 'Item registry',
        // No own interval: ownership changes slowly and chain-verify is a
        // manual, deliberate action. Refreshes on load and its own button.
        refresh(box) {
            return tabs(box, 'items', [
                {
                    key: 'status',
                    label: 'Status',
                    render: async (p) => renderItemStatus(p, await api('items')),
                },
                {
                    key: 'ledger',
                    label: 'Ledger',
                    render: async (p) => renderItemLedger(p, await api('items')),
                },
            ]);
        },
    },
    {
        id: 'perf',
        title: 'Server Perf. & Diag.',
        // Its own, slower interval: the gauges here step once a minute and
        // the history once an hour, so refreshing per second would only cost
        // queries.
        every: 'admin_perf_refresh_secs',
        refresh(box) {
            return tabs(box, 'perf', [
                {
                    key: 'live',
                    label: 'Live',
                    // The 24 h history is not read here at all: a gauge
                    // draws its own in a popup, which fetches it once when
                    // it opens (see showGaugeCharts).
                    render: async (p) => renderServerLive(p, await api('stats')),
                },
                {
                    // The 24 h history is the one payload that grows with the
                    // number of endpoints, so it is fetched only while a tab
                    // that draws it is open (see tabs()).
                    key: 'scripts',
                    label: 'Per script',
                    render: async (p) => renderScripts(p, await api('load')),
                },
                {
                    // Read ONCE and kept: the assessment only changes on a
                    // new release or an explicit Update, so the live refresh
                    // must not keep asking for it.
                    key: 'perf',
                    label: 'Performance',
                    render: async (p) => {
                        if (lastCaps === null) lastCaps = await api('caps');
                        renderPerf(p, lastCaps);
                    },
                },
                {
                    key: 'load',
                    label: 'Load',
                    render: async (p) => renderLoad(p, await api('load')),
                },
            ]);
        },
    },
    {
        id: 'alerts',
        title: 'Alerts and logs',
        refresh(box) {
            return tabs(box, 'alerts', [
                {
                    key: 'alerts',
                    label: 'Alerts',
                    render: async (p) => renderAlerts(p, await api('alerts')),
                },
                {
                    // The log file is read ONLY while this tab is open:
                    // populated on select, then live-followed by the card's
                    // own refresh. The default Alerts tab never pays for it.
                    key: 'logs',
                    label: 'Logs',
                    render: async (p) => { lastLog = await api('log'); renderLogs(p); },
                },
            ]);
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
            // Bulk actions stay put above the scrolling list (see admin.css).
            const view = el('div', 'pane');
            view.append(table);
            box.append(bar, view);
            updateBar();
            sortable(table, 'debug');
            mth.classList.remove('sortable');
            box.append(el('p', 'muted', 'A client submits logs + up to two snapshots and reads out '
                + 'the PIN; datasets self-purge after ' + Math.round(d.ttl / 3600) + ' h.'));
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
            // "Apply and Save": these settings take effect on the next
            // request, not on a restart - the button says so.
            const save = el('button', '', 'Apply and Save');
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
            prop('CPU cores', d.cores === 1 ? '1 (or the host will not say)' : d.cores);
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
        id: 'housekeeping',
        title: 'Housekeeping',
        view: 'settings',
        async refresh(box) {
            const d = await api('housekeeping');
            box.replaceChildren();
            const table = el('table');
            table.append(row(['Database file', fmtBytes(d.db_size), '']));
            const NOTE = {
                reaped: 'reaped on the next sweep',
                kept: 'kept for a player that comes back',
                orphan: 'left behind by a removal',
                off: 'no TTL set - never reaped',
            };
            for (const t of d.tables) {
                const r = row([t.name, t.rows + ' rows']);
                // A loose row that should not exist is the only thing on this
                // card worth colouring: everything else is the design working.
                const bad = t.policy === 'orphan' && t.loose > 0;
                const txt = t.policy === 'off' ? NOTE.off
                    : (t.loose > 0 ? t.loose + ' ' + NOTE[t.policy] : 'nothing loose');
                r.append(el('td', bad ? 'error' : 'muted', txt));
                table.append(r);
            }
            box.append(table);
            box.append(el('p', 'muted', 'Runs by itself, once an hour; the ages are in '
                + 'Configuration. Items, config backups and career stats are never swept, even '
                + 'when the player row has expired: an id comes back with its client and so does '
                + 'everything it owns. Anything "left behind by a removal" should read zero.'));
        },
    },
    {
        id: 'backup',
        title: 'Backup and restore (db incl. config)',
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
    // Bind on the card body, not the table's immediate parent: a table may
    // sit inside a .pane wrapper that is rebuilt on every refresh, and the
    // body is the element that survives one (see the height model in
    // admin.css). Falls back for a table rendered outside a card.
    const box = table.closest('.card-body') || table.parentNode;
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
const LIVE = ['alerts'];

const settings = {};
const timers = {};

async function loadSettings() {
    for (const s of (await api('settings')).settings) settings[s.key] = s.value;
}

// ONE clock for the whole dashboard. Every card used to carry its own
// setInterval, and two cards shared a request only when their timers happened
// to fall in the same turn of the event loop - which stops being true as soon
// as their intervals differ or a timer drifts. A single tick decides who is
// due, so everything due is asked for in one turn and the batcher has
// something to batch (see api). Intervals still live in the server settings:
// they survive a reload and are editable in the settings view. 0 is off.
const CLOCK_MS = 1000;
const nextDue = {};

// A card's own interval, or the shared one for the cards that follow it.
function period(m) {
    const key = m.every || (LIVE.includes(m.id) ? 'admin_refresh_secs' : '');
    return key ? (parseInt(settings[key], 10) || 0) : 0;
}

function tick() {
    const now = Date.now();
    for (const m of MODULES) {
        const secs = period(m);
        if (secs <= 0) {
            delete nextDue[m.id];
        } else if (nextDue[m.id] === undefined) {
            nextDue[m.id] = now + secs * 1000;
        } else if (nextDue[m.id] <= now) {
            nextDue[m.id] = now + secs * 1000;
            refreshModule(m.id);
        }
    }
}

function stopIntervals() {
    for (const name of Object.keys(timers)) {
        clearInterval(timers[name]);
        delete timers[name];
    }
}

function applyIntervals() {
    stopIntervals();
    for (const id of Object.keys(nextDue)) {
        delete nextDue[id];
    }
    // Nobody is reading a background tab, and a poll nobody reads still costs
    // a PHP worker that a client is queueing for. The browser tells us when
    // the page is hidden: while it is, the dashboard asks for nothing and
    // catches up in one pass when it comes back.
    if (document.hidden) {
        return;
    }
    timers.clock = setInterval(tick, CLOCK_MS);
}

document.addEventListener('visibilitychange', () => {
    applyIntervals();
    if (!document.hidden) {
        // Only the cards that keep themselves up to date. The others were
        // read when the page was built and change only when somebody asks
        // them to, so waking all of them here would answer a tab switch
        // with a burst of requests that no card had any use for.
        for (const m of MODULES) {
            if (period(m) > 0) {
                refreshModule(m.id);
            }
        }
    }
});

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
    document.querySelector('header .hrefresh')
        .append(intervalControl('admin_refresh_secs', 'Dashboard refresh interval'));
    refreshAll();
    applyIntervals();
})();
