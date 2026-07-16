'use strict';

/*
 * FOK-server admin dashboard.
 * Each dashboard card is a self-contained module: { id, title, refresh(el) }.
 * To extend the admin UI, append a module to MODULES; nothing else to touch.
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
    return new Date(unix * 1000).toLocaleString();
}

function fmtBytes(n) {
    if (n > 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n > 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
}

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
            stat('Users registered', d.counts.registered);
            stat('Scores stored', d.scores_total);
            stat('DB size', fmtBytes(d.db_size));
            box.append(grid);
            box.append(el('p', 'muted', 'Server v' + d.server_version + ', PHP ' + d.php +
                ', ' + fmtTime(d.now)));
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
            table.append(row(['Hour', 'hello', 'score_submit', 'signal'], 'th'));
            for (const b of buckets) {
                const m = d.load[b];
                table.append(row([
                    b.slice(0, 8) + ' ' + b.slice(8) + ':00',
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
            table.append(row(['ID', 'IP', 'First seen', 'Last seen', 'Hellos', ''], 'th'));
            for (const u of d.users) {
                const online = d.now - u.last_seen <= d.online_window;
                const r = row([u.id, u.ip, fmtTime(u.first_seen), fmtTime(u.last_seen), u.hello_count]);
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
            table.append(row(['#', 'Name', 'Score', 'Level', 'Player', 'Valid', 'Date', ''], 'th'));
            for (const s of d.scores) {
                const r = row([s.rank, s.name, s.score, s.level, s.player_id,
                    s.validated ? 'yes' : 'unchecked', fmtTime(s.created)]);
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
        title: 'Backup and restore',
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

function refreshModule(id) {
    const mod = MODULES.find((m) => m.id === id);
    mod.refresh(boxes[id]).catch((e) => {
        boxes[id].replaceChildren(el('p', 'error', 'Error: ' + e.message));
    });
}

function refreshAll() {
    for (const m of MODULES) refreshModule(m.id);
}

const dash = document.getElementById('dashboard');
for (const m of MODULES) {
    const card = el('section', 'card');
    const head = el('h2', '', m.title);
    const btn = el('button', 'small refresh', 'refresh');
    btn.onclick = () => refreshModule(m.id);
    head.append(btn);
    const box = el('div', 'card-body');
    card.append(head, box);
    dash.append(card);
    boxes[m.id] = box;
}
refreshAll();
setInterval(() => { refreshModule('stats'); }, 30000);
