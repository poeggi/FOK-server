// A minimal connect test through Cloudflare's TURN relay, without the game
// client. Boots the server on php -S against a throwaway data dir holding
// a COPY of ~/.fok-server-turn.json (deleted again at the end), drives
// headless Edge to test/turn-probe.html on the same origin, and prints
// what the page found: whether a credential was minted, whether a
// DataChannel forced onto the relay opened and echoed, and which pair ICE
// picked when a direct path existed beside the relay. The credential is
// fetched and used inside the page and is never printed here. Which
// address family the relay leg took is not readable from a browser (it
// blanks the related address): --family pins it, test/turn-alloc.mjs
// asks the relay.
//
//   node test/turn-probe.mjs            local server, the key from ~
//                                       (FOK_CA_BUNDLE=<pem> when this
//                                       box's PHP curl trusts nothing)
//   node test/turn-probe.mjs --base https://fok-server.poggensee.it
//                                       a deployed server (its own key);
//                                       the page still comes from the
//                                       local php -S on port 8000, which
//                                       the API's CORS allowlist admits
//   ... --family 6 | 4                  pin the relay leg to one address
//                                       family: the TURN urls are pointed
//                                       at the relay's AAAA (or A) record
//                                       as a literal, so the allocation
//                                       can only be made over that family
//
// Needs php, node 22+ and Microsoft Edge on this box. Exit 0 when the
// relayed echo came back, 1 otherwise.
import { spawn } from 'node:child_process';
import { mkdtempSync, copyFileSync, rmSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join } from 'node:path';
import { promises as dns } from 'node:dns';

const args = process.argv.slice(2);
const remote = args.includes('--base') ? args[args.indexOf('--base') + 1].replace(/\/$/, '') : '';
const family = args.includes('--family') ? args[args.indexOf('--family') + 1] : '';
// The relay host as a literal of the asked family: turn.cloudflare.com
// carries both records, and a literal leaves the browser no choice.
let relayLit = '';
if (family === '6') relayLit = '[' + (await dns.resolve6('turn.cloudflare.com'))[0] + ']';
else if (family === '4') relayLit = (await dns.resolve4('turn.cloudflare.com'))[0];
else if (family !== '') { console.error('--family takes 6 or 4'); process.exit(1); }
const port = 8000;
const cdp = 9334;
const edgePath = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find((p) => existsSync(p));
if (!edgePath) { console.error('Microsoft Edge not found'); process.exit(1); }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const data = mkdtempSync(join(tmpdir(), 'fok-turn-probe-'));
const keyFile = join(homedir(), '.fok-server-turn.json');
// Against a deployed server the probe id is bound once and its token kept
// where test/live-protocol.sh keeps its cast's: one "<base> <id> <tok>"
// line per pair, outside the repo. Local runs bind on a throwaway
// database and keep nothing.
const ID = '77777e57';
const tokFile = process.env.FOK_LIVETEST_TOK || join(homedir(), '.fok-server-livetest.tok');
const tokLines = () => existsSync(tokFile) ? readFileSync(tokFile, 'utf8').split(/\r?\n/).filter((l) => l !== '') : [];
const heldTok = remote ? (tokLines().find((l) => l.startsWith(remote + ' ' + ID + ' ')) || '').split(' ')[2] || '' : '';
const adopt = (tok) => {
    if (!remote || !tok || tok === heldTok) return;
    const keep = tokLines().filter((l) => !l.startsWith(remote + ' ' + ID + ' '));
    keep.push(remote + ' ' + ID + ' ' + tok);
    writeFileSync(tokFile, keep.join('\n') + '\n');
};
if (!remote) {
    if (!existsSync(keyFile)) { console.error('missing ' + keyFile); process.exit(1); }
    copyFileSync(keyFile, join(data, 'turn.json'));
}
// A box whose PHP has no CA bundle (Windows) or sits behind a TLS-inspecting
// proxy names one in FOK_CA_BUNDLE; the server's curl then trusts it.
const cainfo = process.env.FOK_CA_BUNDLE ? ['-d', 'curl.cainfo=' + process.env.FOK_CA_BUNDLE] : [];
const php = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'public', ...cainfo, 'test/turn-probe-router.php'],
    { env: { ...process.env, FOK_DATA_DIR: data }, stdio: 'ignore' });
const edge = spawn(edgePath, ['--headless=new', '--remote-debugging-port=' + cdp,
    '--user-data-dir=' + join(data, 'edge'), '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });

let code = 1;
try {
    let up = false;
    for (let i = 0; i < 50 && !up; i++) {
        await sleep(200);
        try { up = (await fetch('http://127.0.0.1:' + port + '/api/hello.php')).status === 405; } catch (e) { /* booting */ }
    }
    if (!up) throw new Error('php -S never answered on ' + port);
    let wsUrl = '';
    for (let i = 0; i < 50 && !wsUrl; i++) {
        await sleep(200);
        try {
            const page = (await (await fetch('http://127.0.0.1:' + cdp + '/json')).json()).find((t) => t.type === 'page');
            if (page) wsUrl = page.webSocketDebuggerUrl;
        } catch (e) { /* booting */ }
    }
    if (!wsUrl) throw new Error('edge never answered');
    const ws = new WebSocket(wsUrl);
    await new Promise((r) => { ws.onopen = r; });
    let seq = 0;
    const pending = new Map();
    ws.onmessage = (m) => { const j = JSON.parse(m.data); if (j.id && pending.has(j.id)) { pending.get(j.id)(j); pending.delete(j.id); } };
    const cmd = (method, params) => new Promise((res) => { const id = ++seq; pending.set(id, res); ws.send(JSON.stringify({ id, method, params: params || {} })); });
    const evalJs = async (expr) => (await cmd('Runtime.evaluate', { expression: expr, returnByValue: true })).result.result.value;
    await cmd('Page.enable');
    const query = (remote ? '?base=' + encodeURIComponent(remote) + '&id=' + ID + (heldTok ? '&tok=' + heldTok : '') : '?id=' + ID)
        + (relayLit ? '&relay=' + encodeURIComponent(relayLit) : '');
    if (relayLit) console.log('relay leg pinned to ' + relayLit);
    await cmd('Page.navigate', { url: 'http://127.0.0.1:' + port + '/turn-probe' + query });
    let result = null;
    for (let i = 0; i < 300 && !result; i++) {
        await sleep(200);
        result = await evalJs('window.__probe || null');
    }
    ws.close();
    if (!result) throw new Error('the page never finished (60 s)');
    adopt(result.tok);
    const show = (r) => r ? `open ${r.openMs} ms, rtt ${r.rttMs} ms, ${r.local.type} ${r.local.addr}`
        + `${r.local.relayProto ? ' via ' + r.local.relayProto : ''}`
        + ` -> ${r.remote.type} ${r.remote.addr}`
        + ` (candidates a ${r.candidates.a}, b ${r.candidates.b}, relay ${r.candidates.relay}`
        + `${r.candidates.relay ? ': ' + Object.entries(r.candidates.by).map(([p, n]) => n + ' ' + p).join(', ') : ''})` : '-';
    console.log('turn.php:   ' + (result.turn ? result.turn.status + (result.turn.error ? ' ' + result.turn.error : ' ttl ' + result.turn.ttl
        + ', ' + result.turn.urls.length + ' urls') : '-'));
    console.log('relay-only: ' + show(result.relay));
    console.log('all:        ' + show(result.all));
    if (result.error) console.log('error:      ' + result.error);
    // What the server said about it: its own TURN lines carry the relay's
    // status and never a key.
    if (!result.ok && !remote && existsSync(join(data, 'php-error.log'))) {
        for (const line of readFileSync(join(data, 'php-error.log'), 'utf8').split(/\r?\n/)) {
            if (/turn/i.test(line)) console.log('server:     ' + line.replace(/^\[[^\]]*\] /, ''));
        }
    }
    console.log(result.ok ? 'TURN PROBE PASSED' : 'TURN PROBE FAILED');
    code = result.ok ? 0 : 1;
} catch (e) {
    console.log('error: ' + (e && e.message || e));
} finally {
    edge.kill();
    php.kill();
    await sleep(500);
    rmSync(data, { recursive: true, force: true });
}
process.exit(code);
