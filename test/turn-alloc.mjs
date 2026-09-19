// A TURN Allocate over UDP, one address family at a time, with a real
// credential from a deployed server: what the relay saw the allocation
// come from (XOR-MAPPED-ADDRESS) and what it handed out
// (XOR-RELAYED-ADDRESS). The one question a browser cannot answer, since
// it blanks a relay candidate's related address. Prints families, timing
// and the relayed address; never the credential.
//
//   node test/turn-alloc.mjs 6        the relay's AAAA record, UDP/IPv6
//   node test/turn-alloc.mjs 4        its A record, UDP/IPv4
//   LIVE_BASE=https://host/staging    another deployed server
//
// Uses the probe's id 77777e57 and its token from ~/.fok-server-livetest.tok
// (bound by test/turn-probe.mjs --base). Measured 2026-09-19: both
// families allocate in 44-66 ms; the relayed address is IPv4 either way,
// which is Cloudflare's documented behaviour.
import { createSocket } from 'node:dgram';
import { promises as dns } from 'node:dns';
import { createHash, createHmac, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const fam = process.argv[2] === '4' ? 4 : 6;
const BASE = (process.env.LIVE_BASE || 'https://fok-server.poggensee.it').replace(/\/$/, '');
const ID = '77777e57';
const tok = (readFileSync(join(homedir(), '.fok-server-livetest.tok'), 'utf8').split(/\r?\n/)
    .find((l) => l.startsWith(BASE + ' ' + ID + ' ')) || '').split(' ')[2];
if (!tok) { console.error('no token for ' + ID); process.exit(1); }
const t = await (await fetch(BASE + '/api/turn.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: ID, tok }) })).json();
if (!t.ok) { console.error('turn.php: ' + JSON.stringify(t)); process.exit(1); }
const srv = t.ice.find((s) => s.username);
const user = srv.username, pass = srv.credential;
const host = fam === 6 ? (await dns.resolve6('turn.cloudflare.com'))[0] : (await dns.resolve4('turn.cloudflare.com'))[0];
console.log(`relay ${host} over UDP/IPv${fam}, credential ttl ${t.ttl}s`);

const COOKIE = 0x2112a442;
const attr = (type, val) => {
    const pad = (4 - (val.length % 4)) % 4;
    const b = Buffer.alloc(4 + val.length + pad);
    b.writeUInt16BE(type, 0); b.writeUInt16BE(val.length, 2); val.copy(b, 4);
    return b;
};
const msg = (type, tid, attrs, key) => {
    let body = Buffer.concat(attrs);
    const head = (len) => { const h = Buffer.alloc(20); h.writeUInt16BE(type, 0); h.writeUInt16BE(len, 2); h.writeUInt32BE(COOKIE, 4); tid.copy(h, 8); return h; };
    if (key) {
        const mi = createHmac('sha1', key).update(Buffer.concat([head(body.length + 24), body])).digest();
        body = Buffer.concat([body, attr(0x0008, mi)]);
    }
    return Buffer.concat([head(body.length), body]);
};
const parse = (b) => {
    const out = { type: b.readUInt16BE(0), attrs: {} };
    let o = 20;
    const end = 20 + b.readUInt16BE(2);
    while (o + 4 <= end) {
        const ty = b.readUInt16BE(o), len = b.readUInt16BE(o + 2);
        out.attrs[ty] = b.subarray(o + 4, o + 4 + len);
        o += 4 + len + ((4 - (len % 4)) % 4);
    }
    return out;
};
const xaddr = (v, tid) => {
    const f = v[1], port = v.readUInt16BE(2) ^ (COOKIE >> 16);
    if (f === 1) { const a = v.readUInt32BE(4) ^ COOKIE; return { fam: 4, addr: [a >>> 24, (a >> 16) & 255, (a >> 8) & 255, a & 255].join('.'), port }; }
    const x = Buffer.concat([Buffer.from([0x21, 0x12, 0xa4, 0x42]), tid]);
    const parts = [];
    for (let i = 0; i < 16; i += 2) parts.push(((v[4 + i] ^ x[i]) << 8 | (v[5 + i] ^ x[i + 1])).toString(16));
    return { fam: 6, addr: parts.join(':'), port };
};
const sock = createSocket(fam === 6 ? 'udp6' : 'udp4');
const ask = (buf) => new Promise((res, rej) => {
    const to = setTimeout(() => rej(new Error('no answer in 3 s')), 3000);
    sock.once('message', (m) => { clearTimeout(to); res(parse(m)); });
    sock.send(buf, 3478, host);
});
try {
    const tid = randomBytes(12);
    const t0 = Date.now();
    let r = await ask(msg(0x0003, tid, [attr(0x0019, Buffer.from([17, 0, 0, 0]))]));
    const code = r.attrs[0x0009] ? (r.attrs[0x0009][2] & 7) * 100 + r.attrs[0x0009][3] : 0;
    if (r.type !== 0x0113 || code !== 401) throw new Error('expected 401 challenge, got type 0x' + r.type.toString(16) + ' code ' + code);
    const realm = r.attrs[0x0014].toString(), nonce = r.attrs[0x0015];
    const key = createHash('md5').update(user + ':' + realm + ':' + pass).digest();
    const tid2 = randomBytes(12);
    r = await ask(msg(0x0003, tid2, [attr(0x0019, Buffer.from([17, 0, 0, 0])), attr(0x0006, Buffer.from(user)),
        attr(0x0014, Buffer.from(realm)), attr(0x0015, nonce)], key));
    const ms = Date.now() - t0;
    if (r.type !== 0x0103) {
        const c = r.attrs[0x0009] ? (r.attrs[0x0009][2] & 7) * 100 + r.attrs[0x0009][3] : 0;
        throw new Error('allocate failed: type 0x' + r.type.toString(16) + ' code ' + c + ' ' + (r.attrs[0x0009] ? r.attrs[0x0009].subarray(4).toString() : ''));
    }
    const mapped = xaddr(r.attrs[0x0020], tid2), relayed = xaddr(r.attrs[0x0016], tid2);
    const life = r.attrs[0x000d] ? r.attrs[0x000d].readUInt32BE(0) : 0;
    console.log(`ALLOCATED in ${ms} ms (two round trips): realm ${realm}, lifetime ${life}s`);
    console.log(`  the relay saw us from  IPv${mapped.fam} (mapped, port ${mapped.port})`);
    console.log(`  relayed address        IPv${relayed.fam} ${relayed.addr}:${relayed.port}`);
    // Give it back: a Refresh with lifetime 0.
    const tid3 = randomBytes(12);
    r = await ask(msg(0x0004, tid3, [attr(0x000d, Buffer.alloc(4)), attr(0x0006, Buffer.from(user)),
        attr(0x0014, Buffer.from(realm)), attr(0x0015, nonce)], key));
    console.log('  released: ' + (r.type === 0x0104 ? 'yes' : 'type 0x' + r.type.toString(16)));
} catch (e) {
    console.log('FAILED: ' + e.message);
} finally {
    sock.close();
}
