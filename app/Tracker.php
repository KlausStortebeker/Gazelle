<?php

// TODO: The following actions are used, turn them into methods
// remove_users

namespace Gazelle;

use Gazelle\Enum\LeechType;
use Gazelle\Enum\LeechReason;
use Gazelle\Util\Irc;

class Tracker extends Base {
    final public const STATS_MAIN = 0;
    final public const STATS_USER = 1;

    protected static array $Requests = [];
    protected string|false $error    = false;

    public function requestList(): array {
        return self::$Requests;
    }

    public function last_error(): string|false {
        return $this->error;
    }

    public function addToken(Torrent $torrent, User $user): bool {
        return $this->update_tracker('add_token', [
            'info_hash' => $torrent->infohashEncoded(),
            'userid'    => $user->id(),
        ]);
    }

    public function removeToken(Torrent $torrent, User $user): bool {
        return $this->update_tracker('remove_token', [
            'info_hash' => $torrent->infohashEncoded(),
            'userid'    => $user->id(),
        ]);
    }

    public function addTorrent(Torrent $torrent): bool {
        return $this->update_tracker('add_torrent', [
            'info_hash'   => $torrent->flush()->infohashEncoded(),
            'id'          => $torrent->id(),
            'freetorrent' => 0,
        ]);
    }

    public function modifyTorrent(TorrentAbstract $torrent, LeechType $leechType): bool {
        return $this->update_tracker('update_torrent', [
            'info_hash'   => $torrent->infohashEncoded(),
            'freetorrent' => $leechType->value
        ]);
    }

    public function modifyPasskey(string $old, string $new): bool {
        return $this->update_tracker('change_passkey', [
            'oldpasskey' => $old,
            'newpasskey' => $new,
        ]);
    }

    public function addUser(User $user): bool {
        self::$cache->increment('stats_user_count');
        return $this->update_tracker('add_user', [
            'passkey' => $user->announceKey(),
            'id'      => $user->id(),
            'visible' => $user->isVisible() ? '1' : '0',
        ]);
    }

    public function refreshUser(User $user): bool {
        return $this->update_tracker('update_user', [
            'passkey'   => $user->announceKey(),
            'can_leech' => $user->canLeech() ? '1' : '0',
            'visible'   => $user->isVisible() ? '1' : '0',
        ]);
    }

    public function removeUser(User $user): bool {
        return $this->update_tracker('remove_user', [
            'passkey' => $user->announceKey(),
        ]);
    }

    public function addWhitelist(string $peer): bool {
        return $this->update_tracker('add_whitelist', [
            'peer_id' => $peer,
        ]);
    }

    public function modifyWhitelist(string $old, string $new): bool {
        return $this->update_tracker('edit_whitelist', [
            'old_peer_id' => $old,
            'new_peer_id' => $new,
        ]);
    }

    public function removeWhitelist(string $peer): bool {
        return $this->update_tracker('remove_whitelist', [
            'peer_id' => $peer,
        ]);
    }

    public function modifyAnnounceInterval(int $interval): bool {
        return $this->update_tracker('update_announce_interval', [
            'new_announce_interval' => $interval,
        ]);
    }

    public function modifyAnnounceJitter(int $interval): bool {
        return $this->update_tracker('update_announce_jitter', [
            'new_announce_jitter' => $interval,
        ]);
    }

    public function expireFreeleechTokens(string $payload): int {
        $clear = [];
        $expire = [];
        foreach (explode(',', $payload) as $item) {
            [$userId, $torrentId] = array_map('intval', explode(':', $item));
            if ($userId && $torrentId) {
                $expire[] = [$userId, $torrentId];
                $clear[$userId] = true;
            }
        }

        if (!$expire) {
            return 0;
        }
        self::$db->begin_transaction();
        self::$db->prepared_query("
            CREATE TEMPORARY TABLE expire_freeleech (
                UserID int NOT NULL,
                TorrentID int NOT NULL,
                PRIMARY KEY (UserID, TorrentID)
            )
        ");
        self::$db->prepared_query("
            INSERT IGNORE INTO expire_freeleech (UserID, TorrentID) VALUES
            " . placeholders($expire, '(?, ?)'),
            ...array_merge(...$expire)
        );
        self::$db->prepared_query("
            UPDATE users_freeleeches uf
            INNER JOIN expire_freeleech ef USING (UserID, TorrentID)
            SET
                Expired = true
            WHERE
                Expired = false
        ");
        $affected = self::$db->affected_rows();
        self::$db->prepared_query("
            DROP TABLE IF EXISTS expire_freeleech
        ");
        self::$db->commit();
        if (DEBUG_TRACKER_TOKEN_EXPIRE) {
            $filename = (string)DEBUG_TRACKER_TOKEN_EXPIRE; // phpstan, grrr
            $out = fopen($filename, 'a');
            if ($out !== false) {
                fprintf($out, "%s u=%d t=%d s=%s\n",
                    date('Y-m-d H:i:s'),
                    count($clear),
                    count($expire),
                    $payload,
                );
                fclose($out);
            }
        }
        self::$cache->delete_multi(array_map(fn($id) => "users_tokens_$id", array_keys($clear)));
        return $affected;
    }

    /**
     * Send a GET request over a socket directly to the tracker
     * For example, Tracker::update_tracker('change_passkey', array('oldpasskey' => OLD_PASSKEY, 'newpasskey' => NEW_PASSKEY)) will send the request:
     * GET /tracker_32_char_secret_code/update?action=change_passkey&oldpasskey=OLD_PASSKEY&newpasskey=NEW_PASSKEY HTTP/1.1
     */
    public function update_tracker(string $Action, array $Updates, bool $ToIRC = false): bool {
        if (DISABLE_TRACKER) {
            return true;
        }
        // Build request
        $url = "/update?action=$Action";
        foreach ($Updates as $k => $v) {
            $url .= "&$k=$v";
        }

        $this->error = false;
        if ($this->request(TRACKER_SECRET, $url, 5) === false) {
            if (self::$cache->get_value('ocelot_error_reported') === false) {
                Irc::sendMessage(IRC_CHAN_DEV, "Failed to update ocelot: {$this->error} : $url");
                self::$cache->cache_value('ocelot_error_reported', true, 180);
            }
            return false;
        }
        return true;
    }

    /**
     * Get global peer stats from the tracker
     *
     * @return array|false (0 => $Leeching, 1 => $Seeding) or false if request failed
     */
    public function global_peer_count(): array|false {
        $Stats = $this->report(self::STATS_MAIN);
        if (isset($Stats['leechers tracked']) && isset($Stats['seeders tracked'])) {
            $Leechers = $Stats['leechers tracked'];
            $Seeders = $Stats['seeders tracked'];
        } else {
            return false;
        }
        return [$Leechers, $Seeders];
    }

    /**
     * Get user context from the tracker
     */
    public function userReport(User $user): array {
        return $this->report(self::STATS_USER, ['key' => $user->announceKey()]);
    }

    /**
     * Get whatever info the tracker has to report
     */
    public function info(): array {
        return $this->report(self::STATS_MAIN);
    }

    /**
     * Get whatever info the tracker has to report on memory allocations
     */
    public function infoMemoryAlloc(): string {
        return DISABLE_TRACKER
            ? "tracker is disabled by configuration"
            : (string)$this->request(TRACKER_REPORTKEY, '/report?jemalloc=plain', 3);
    }

    /**
     * Send a stats request to the tracker and process the results
     *
     * @return array with stats in named keys or empty if the request failed
     */
    protected function report(int $Type, false|array $Params = false): array {
        if (DISABLE_TRACKER) {
            return [];
        }
        $url = '/report?';
        if ($Type === self::STATS_MAIN) {
            $url .= 'get=stats';
        } elseif ($Type === self::STATS_USER && !empty($Params['key'])) {
            $url .= "get=user&key={$Params['key']}";
        } else {
            return [];
        }
        $response = $this->request(TRACKER_REPORTKEY, $url, 5);
        if ($response === false || $response === "") {
            return [];
        }
        if ($Type === self::STATS_USER) {
            return json_decode($response, true);
        }
        $list = [];
        foreach (explode("\n", $response) as $metric) {
            if (preg_match('/^(Uptime|(?:jemalloc_)?version): (.*)$/', $metric, $match)) {
                $list[strtolower($match[1])] = $match[2];
            } else {
                [$value, $key] = explode(" ", $metric, 2);
                $list[$key] = str_contains($value, ".") ? (float)$value : (int)$value;
            }
        }
        return $list;
    }

    /**
     * Send a request to the tracker
     *
     * @return false|string tracker response message or false if the request failed
     */
    protected function request(string $secret, string $url, int $MaxAttempts): false|string {
        if (DISABLE_TRACKER) {
            return false;
        }
        $Header = "GET /$secret$url HTTP/1.1\r\nHost: " . TRACKER_NAME . "\r\nConnection: Close\r\n\r\n";
        $Attempts = 0;
        $Success = false;
        $StartTime = microtime(true);
        $Data = "";
        $response = "";
        $code = 0;
        $sleep = 1200000; // 1200ms
        while (!$Success && $Attempts++ < $MaxAttempts) {
            // Send request
            $socket = fsockopen(TRACKER_HOST, TRACKER_PORT, $ErrorNum, $ErrorString);
            if ($socket) {
                if (fwrite($socket, $Header) === false) {
                    $this->error = "Failed to fwrite()";
                    usleep((int)$sleep);
                    $sleep *= 1.5; // exponential backoff
                    continue;
                }
            } else {
                $this->error = "Failed to fsockopen(" . TRACKER_HOST . ":" . TRACKER_PORT . ") - $ErrorNum - $ErrorString";
                usleep((int)$sleep);
                $sleep *= 1.5; // exponential backoff
                continue;
            }

            // Check for response.
            $response = '';
            while (!feof($socket)) {
                $response .= fread($socket, 1024);
            }
            if (preg_match('/HTTP\/1.1 (\d+)/', $response, $match)) {
                $code = $match[1];
            } else {
                break;
            }
            $DataStart = strpos($response, "\r\n\r\n") + 4;
            $DataEnd = strrpos($response, "\n");
            if ($DataEnd > $DataStart) {
                $Data = substr($response, $DataStart, $DataEnd - $DataStart);
            } else {
                $Data = "";
            }
            $Status = substr($response, $DataEnd + 1);
            if ($code == 200 || $Status == "success") {
                $Success = true;
            }
        }
        $path_array = explode("/", $url, 2);
        $Request = [
            'path'     => array_pop($path_array), // strip authkey from path
            'response' => ($Success ? $Data : $response),
            'code'     => $code,
            'status'   => ($Success ? 'ok' : 'failed'),
            'time'     => 1000 * (microtime(true) - $StartTime),
        ];
        self::$Requests[] = $Request;
        if ($Success) {
            return $Data;
        }
        return false;
    }
}
