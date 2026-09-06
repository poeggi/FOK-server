<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Caps.php';

/**
 * Quick-match queue: pairs two waiting players into a duel. The longer-
 * waiting player becomes the "offerer" (creates the WebRTC offer and the
 * shared duel seed); the newcomer is the "answerer". After pairing, the
 * peers continue via the normal signaling flow (signal.php).
 *
 * The queue lives in shared memory, and only there. A seeker is worth
 * FOK_MATCH_WINDOW seconds and polls once or twice a second for as long as
 * it waits, so every poll used to take the single write lock the whole
 * database shares - to restamp a row nothing durable ever reads. There is
 * deliberately no database transport to fall back to, exactly as for the
 * signal mailbox and the tracked connections (see Signals, ConnTrack): on a
 * host without usable shared memory a seeker simply keeps waiting.
 *
 * Pairing is settled by atomic adds instead of a transaction. A seeker only
 * ever attempts a peer that has waited LONGER than it has - an earlier
 * arrival, and the smaller id in the tie that cannot otherwise happen - so
 * of any two seekers exactly one attempts the pair, and the two can never
 * hand each other a match at the same moment. Arrival is kept to the
 * microsecond for exactly that reason: whole seconds would leave the two
 * sides of a quick-match pair, which arrive milliseconds apart, tied on
 * nothing but their ids, and the offerer is meant to be the one that
 * waited longer.
 */
final class Matchmaking
{
    /**
     * One entry per waiting seeker: ['since' => float, 'poll' => int]. The
     * wait began at `since`, a microtime; everything that reports it to a
     * human wants whole seconds and casts.
     */
    private const QUEUE = FOK_APCU_NS . 'mm:q:';

    /**
     * What has been handed to one seeker: a match to collect on its next
     * poll ('match', naming the peer, the role and the wait it began), or
     * the 'busy' marker a seeker puts on ITSELF while it delivers a match to
     * somebody else, so that nobody delivers one to it in the same breath.
     */
    private const MATCH = FOK_APCU_NS . 'mm:m:';

    // A delivered match waits for the peer's next poll. The client polls at
    // 1-2 Hz, so this only has to outlast a client that stopped asking - and
    // only until the pairing it names would be stale anyway.
    private const MATCH_TTL = 300;

    // The busy marker guards one pairing attempt: a handful of adds and
    // deletes. Short enough that a request that dies mid-attempt costs its
    // seeker one poll, long enough to cover the attempt itself.
    private const BUSY_TTL = 2;

    /** @return array one of {waiting:true} | {matched:id, role:offerer|answerer} */
    public static function seek(string $id): array
    {
        if (!Caps::apcu()) {
            return ['waiting' => true];
        }
        $now = time();
        $done = self::collect($id);
        if ($done !== null) {
            return $done;
        }
        // Re-register: the wait started when this seeker first asked, and
        // every poll refreshes both the stamp the peer-select reads and the
        // entry's own TTL, so a seeker that stops asking expires by itself.
        $cur = apcu_fetch(self::QUEUE . $id);
        apcu_store(self::QUEUE . $id, [
            'since' => is_array($cur) ? (float)$cur['since'] : microtime(true),
            'poll' => $now,
        ], FOK_MATCH_WINDOW);
        $cands = self::candidates($id, $now);
        if ($cands === []) {
            return ['waiting' => true];
        }
        if (!apcu_add(self::MATCH . $id, ['kind' => 'busy'], self::BUSY_TTL)) {
            // Somebody is already on this seeker's own slot: either a match
            // delivered since the collect above - take it - or a peer that
            // is mid-attempt on us, in which case its attempt wins and this
            // one waits for the next poll.
            return self::collect($id) ?? ['waiting' => true];
        }
        foreach ($cands as $c) {
            // The peer waited longer: it gets the offerer role and learns
            // about the match on its next seek poll. The add is what settles
            // the pairing - it fails against a peer that already holds a
            // match or is mid-attempt itself, and then the next candidate is
            // tried instead.
            $handed = apcu_add(self::MATCH . $c['id'], [
                'kind' => 'match',
                'peer' => $id,
                'role' => 'offerer',
                'since' => $c['since'],
            ], self::MATCH_TTL);
            if ($handed) {
                apcu_delete(self::QUEUE . $c['id']);
                apcu_delete(self::QUEUE . $id);
                apcu_delete(self::MATCH . $id);
                return ['matched' => $c['id'], 'role' => 'answerer'];
            }
        }
        apcu_delete(self::MATCH . $id);
        return ['waiting' => true];
    }

    /**
     * Leave the queue. A match already handed to this player is left alone:
     * the peer has been told about it and is waiting for the handshake, so
     * a cancel that arrives after the pairing cannot undo it.
     */
    public static function cancel(string $id): void
    {
        if (Caps::apcu()) {
            apcu_delete(self::QUEUE . $id);
        }
    }

    /**
     * The seekers still actively polling, id => the second they started
     * looking (the admin Duels card, see ConnTrack::listDuels). A seeker
     * that went quiet is filtered out rather than waited out: entry expiry
     * is lazy, and the card must not show a ghost as "matchmaking". The keys
     * carry the integer coercion queue() describes.
     * @return array<string,int>
     */
    public static function seekers(): array
    {
        $fresh = time() - FOK_MATCH_WINDOW;
        $out = [];
        foreach (self::queue() as $pid => $q) {
            if ($q['poll'] > $fresh) {
                $out[$pid] = (int)$q['since'];
            }
        }
        return $out;
    }

    /**
     * The admin client view: since when this player has been looking, and
     * the peer it was handed if it has not collected the match yet. Null if
     * it is not in the queue at all.
     * @return array{since:int,matched_with:?string}|null
     */
    public static function stateOf(string $id): ?array
    {
        if (!Caps::apcu()) {
            return null;
        }
        $m = apcu_fetch(self::MATCH . $id);
        if (is_array($m) && $m['kind'] === 'match') {
            return ['since' => (int)$m['since'], 'matched_with' => (string)$m['peer']];
        }
        $q = apcu_fetch(self::QUEUE . $id);
        return is_array($q) ? ['since' => (int)$q['since'], 'matched_with' => null] : null;
    }

    /**
     * Take the match this seeker was handed, if there is one, and leave the
     * queue with it. Both the delivery and the seeker's own entry go, so a
     * match is collected exactly once.
     * @return array{matched:string,role:string}|null
     */
    private static function collect(string $id): ?array
    {
        $e = apcu_fetch(self::MATCH . $id);
        if (!is_array($e) || $e['kind'] !== 'match') {
            return null;
        }
        apcu_delete(self::MATCH . $id);
        apcu_delete(self::QUEUE . $id);
        return ['matched' => (string)$e['peer'], 'role' => (string)$e['role']];
    }

    /**
     * The seekers this one may pair with, longest wait first: those still
     * polling that arrived BEFORE it did, with the smaller id breaking a tie
     * two microtimes practically never reach. That total order is what makes
     * exactly one side of any pair the one that attempts it.
     * @return list<array{id:string,since:float}>
     */
    private static function candidates(string $id, int $now): array
    {
        $q = self::queue();
        $mine = (float)($q[$id]['since'] ?? $now);
        $fresh = $now - FOK_MATCH_WINDOW;
        $out = [];
        foreach ($q as $pid => $e) {
            // An all-digit id comes back as an integer key (see queue()), and
            // the order below is a comparison between ids.
            $pid = (string)$pid;
            $since = (float)$e['since'];
            if ($pid === $id || $e['poll'] <= $fresh) {
                continue;
            }
            if ($since > $mine || ($since === $mine && $pid > $id)) {
                continue;
            }
            $out[] = ['id' => $pid, 'since' => $since];
        }
        usort($out, static fn(array $x, array $y): int
            => [$x['since'], $x['id']] <=> [$y['since'], $y['id']]);
        return $out;
    }

    /**
     * Every queued seeker, keyed by id. The scan only ever covers players
     * looking for a duel right now - the TTL is FOK_MATCH_WINDOW - and only
     * a seek and the admin card ask for it. An id of nothing but digits is a
     * valid id and PHP makes it an INTEGER array key, so a caller that
     * passes a key on as an id casts it back.
     * @return array<string,array{since:float,poll:int}>
     */
    private static function queue(): array
    {
        if (!Caps::apcu()) {
            return [];
        }
        $out = [];
        $cut = strlen(self::QUEUE);
        foreach (new APCUIterator('/^' . preg_quote(self::QUEUE, '/') . '/') as $e) {
            if (is_array($e['value'])) {
                $out[substr($e['key'], $cut)] = $e['value'];
            }
        }
        return $out;
    }
}
