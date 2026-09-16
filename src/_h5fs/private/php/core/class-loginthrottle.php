<?php

/**
 * Limits failed admin logins per client (IPv6 clients are grouped by /64).
 * After MAX_FAILURES failed attempts within WINDOW seconds further attempts
 * of that client are rejected for LOCK seconds. The state is kept in one
 * small JSON file with a bounded number of entries.
 */
class LoginThrottle {
    private const MAX_FAILURES = 5;
    private const WINDOW = 900; // 15 minutes
    private const LOCK = 900; // 15 minutes
    private const MAX_ENTRIES = 10000;
    private const STATE_FILE = 'login-throttle.json';

    private string $state_path;
    private string $client_key;

    public function __construct(Setup $setup) {
        $dir = $setup->get('HAS_WRITABLE_CACHE_PRV')
            ? $setup->get('CACHE_PRV_PATH')
            : rtrim(sys_get_temp_dir(), '/\\') . '/h5fs-' . substr(sha1($setup->get('ROOT_PATH')), 0, 12);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $this->state_path = $dir . '/' . self::STATE_FILE;
        $this->client_key = sha1(Util::client_id((string)$setup->get('REMOTE_ADDR')));
    }

    /**
     * Seconds until the client may try again, 0 if not locked. Returns null
     * if the state can't be read (callers should fail closed).
     */
    public function retry_after(): ?int {
        return $this->with_state(function (array &$state): int {
            $entry = $state[$this->client_key] ?? null;
            $locked_until = is_array($entry) ? (int)($entry['locked_until'] ?? 0) : 0;
            return max(0, $locked_until - time());
        });
    }

    public function record_failure(): void {
        $this->with_state(function (array &$state): int {
            $now = time();
            $entry = $state[$this->client_key] ?? null;
            if (!is_array($entry) || $now - (int)($entry['first'] ?? 0) > self::WINDOW) {
                $entry = ['first' => $now, 'count' => 0, 'locked_until' => 0];
            }
            $entry['count'] = (int)$entry['count'] + 1;
            $entry['last'] = $now;
            if ($entry['count'] >= self::MAX_FAILURES) {
                $entry['locked_until'] = $now + self::LOCK;
                $entry['first'] = $now;
                $entry['count'] = 0;
            }
            unset($state[$this->client_key]);
            $state[$this->client_key] = $entry;
            return 0;
        });
    }

    public function reset(): void {
        $this->with_state(function (array &$state): int {
            unset($state[$this->client_key]);
            return 0;
        });
    }

    private function with_state(callable $fn): ?int {
        $handle = @fopen($this->state_path, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                return null;
            }
            $state = json_decode((string)stream_get_contents($handle), true);
            $state = is_array($state) ? $this->prune($state) : [];

            $result = $fn($state);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state));
            fflush($handle);
            return $result;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function prune(array $state): array {
        $now = time();
        foreach ($state as $key => $entry) {
            if (
                !is_array($entry)
                || ((int)($entry['locked_until'] ?? 0) <= $now && $now - (int)($entry['last'] ?? 0) > self::WINDOW)
            ) {
                unset($state[$key]);
            }
        }
        // entries are kept in order of their last update, drop the oldest
        if (count($state) > self::MAX_ENTRIES) {
            $state = array_slice($state, -self::MAX_ENTRIES, null, true);
        }
        return $state;
    }
}
