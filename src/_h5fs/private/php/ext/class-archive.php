<?php

class Archive {
    private const MAX_FILE_COUNT = 5000;
    private const MAX_DIR_COUNT = 1000;
    private const MAX_DEPTH = 32;
    private const MAX_TOTAL_BYTES = 2147483648; // 2 GiB
    private const MAX_SCAN_DURATION_NS = 2000000000; // 2 seconds
    private const DEFAULT_MAX_CONCURRENT = 4;
    private const DEFAULT_MAX_CONCURRENT_PER_CLIENT = 1;
    private const DEFAULT_MAX_DURATION = 3600; // seconds
    private const DEFAULT_MIN_RATE = 32768; // bytes per second
    private const DEFAULT_MIN_RATE_GRACE = 30; // seconds
    private const CLIENT_BUCKETS = 256;
    private const MAX_SLOTS = 64;
    private const STREAM_CHUNK_SIZE = 65536; // 64 KiB
    private const NULL_BYTE = "\0";
    private const TAR_PASSTHRU_CMD = 'cd [ROOTDIR] && tar --no-recursion -c -- [DIRS] [FILES]';
    private const ZIP_PASSTHRU_CMD = 'cd [ROOTDIR] && zip - -- [FILES]';

    private string $base_path;
    private array $dirs = [];
    private array $files = [];
    private int $total_bytes = 0;
    private int $deadline = 0;
    private bool $aborted = false;
    /** @var resource[] */
    private array $lock_handles = [];
    private bool $output_started = false;
    private bool $stream_aborted = false;
    private int $stream_started_ns = 0;
    private int $stream_bytes = 0;

    public function __construct(private Context $context) {}

    public function output(string $type, string $base_href, $hrefs): bool {
        if (self::has_dot_segments($base_href)) {
            return false;
        }
        $this->base_path = $this->context->to_path($base_href);
        if (!$this->context->is_managed_path($this->base_path)) {
            return false;
        }

        if (!$this->acquire_slots()) {
            return false;
        }
        register_shutdown_function([$this, 'release_slots']);

        try {
            $this->dirs = [];
            $this->files = [];
            $this->total_bytes = 0;
            $this->aborted = false;
            $this->deadline = hrtime(true) + self::MAX_SCAN_DURATION_NS;

            $requested = $this->add_hrefs($hrefs);

            if ($requested === 0 && !$this->aborted) {
                // nothing selected: download the whole base folder
                $this->add_dir($this->base_path, '.', 0);
            }

            if ($this->aborted || (count($this->dirs) === 0 && count($this->files) === 0)) {
                return false;
            }

            return match ($type) {
                'php-tar' => $this->php_tar($this->dirs, $this->files),
                'shell-tar' => $this->shell_cmd(self::TAR_PASSTHRU_CMD),
                'shell-zip' => $this->shell_cmd(self::ZIP_PASSTHRU_CMD),
                default => false
            };
        } finally {
            $this->release_slots();
        }
    }

    /**
     * True once archive bytes have been sent, i.e. it is too late to report
     * an error as JSON (the download is truncated instead).
     */
    public function has_started_output(): bool {
        return $this->output_started;
    }

    /**
     * Limits concurrent downloads with a small number of lock "slots" instead
     * of a single global lock, so one (slow) client cannot block everybody:
     * every download needs one of the per-client slots of its client bucket
     * and one of the global slots.
     */
    private function acquire_slots(): bool {
        $lock_dir = $this->get_lock_dir();
        $lock_prefix = $lock_dir . '/h5fs-archive-' . substr(sha1($this->get_root_id()), 0, 12);

        $max_concurrent = min(self::MAX_SLOTS, $this->int_option('download.maxConcurrent', self::DEFAULT_MAX_CONCURRENT, 1));
        $max_per_client = $this->int_option('download.maxConcurrentPerClient', self::DEFAULT_MAX_CONCURRENT_PER_CLIENT, 1);
        $bucket = $this->get_client_bucket();

        if (
            !$this->acquire_one_slot($lock_prefix . '-client-' . $bucket . '-', min($max_per_client, $max_concurrent))
            || !$this->acquire_one_slot($lock_prefix . '-slot-', $max_concurrent)
        ) {
            $this->release_slots();
            return false;
        }
        return true;
    }

    private function acquire_one_slot(string $prefix, int $count): bool {
        for ($i = 0; $i < $count; $i += 1) {
            $handle = @fopen($prefix . $i . '.lock', 'c');
            if ($handle === false) {
                continue;
            }
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $this->lock_handles[] = $handle;
                return true;
            }
            @fclose($handle);
        }
        return false;
    }

    public function release_slots(): void {
        foreach ($this->lock_handles as $handle) {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
        $this->lock_handles = [];
    }

    private function get_lock_dir(): string {
        $setup = $this->context->get_setup();
        if ($setup->get('HAS_WRITABLE_CACHE_PRV')) {
            return $setup->get('CACHE_PRV_PATH');
        }
        return rtrim(sys_get_temp_dir(), '/\\');
    }

    private function get_root_id(): string {
        $configured_root = $this->context->get_setup()->get('ROOT_PATH');
        return realpath($configured_root) ?: $configured_root;
    }

    /**
     * Maps the client address to one of a fixed number of buckets, so the
     * number of lock files stays bounded. IPv6 clients are grouped by /64,
     * since a single client usually controls a whole /64.
     */
    private function get_client_bucket(): int {
        $addr = (string)$this->context->get_setup()->get('REMOTE_ADDR');
        $packed = @inet_pton($addr);
        if ($packed !== false && strlen($packed) === 16) {
            $addr = substr($packed, 0, 8);
        }
        return crc32($addr) % self::CLIENT_BUCKETS;
    }

    private function int_option(string $keypath, int $default, int $min): int {
        $value = $this->context->query_option($keypath, $default);
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return $default;
        }
        return max($min, (int)$value);
    }

    private function begin_stream(): void {
        // Handle client disconnects ourselves (see send_chunk()), so locks are
        // released and spawned archive processes are terminated reliably.
        ignore_user_abort(true);
        $this->output_started = false;
        $this->stream_aborted = false;
        $this->stream_bytes = 0;
        $this->stream_started_ns = hrtime(true);
    }

    /**
     * Sends a chunk to the client and aborts the download if it exceeds the
     * maximum duration or if the client consumes it too slowly. Output calls
     * block while the client does not read, so the elapsed wall clock time
     * reflects the client's reading speed.
     */
    private function send_chunk(string $data): bool {
        if ($this->stream_aborted) {
            return false;
        }
        if ($data === '') {
            return true;
        }

        $this->output_started = true;
        echo $data;
        @ob_flush();
        @flush();
        $this->stream_bytes += strlen($data);

        if (connection_aborted()) {
            $this->stream_aborted = true;
            return false;
        }

        $elapsed = (hrtime(true) - $this->stream_started_ns) / 1e9;
        $max_duration = $this->int_option('download.maxDuration', self::DEFAULT_MAX_DURATION, 1);
        $min_rate = $this->int_option('download.minRate', self::DEFAULT_MIN_RATE, 0);
        $grace = $this->int_option('download.minRateGrace', self::DEFAULT_MIN_RATE_GRACE, 0);

        if (
            $elapsed > $max_duration
            || ($min_rate > 0 && $elapsed > $grace && $this->stream_bytes / $elapsed < $min_rate)
        ) {
            $this->stream_aborted = true;
            return false;
        }

        return true;
    }

    private function quota_exceeded(): bool {
        if ($this->aborted || hrtime(true) >= $this->deadline) {
            $this->aborted = true;
            return true;
        }
        return false;
    }

    private function shell_cmd(string $cmd): bool {
        $cmd = str_replace('[ROOTDIR]', escapeshellarg($this->base_path), $cmd);
        $cmd = str_replace('[DIRS]', count($this->dirs) ? implode(' ', array_map('escapeshellarg', $this->dirs)) : '', $cmd);
        $cmd = str_replace('[FILES]', count($this->files) ? implode(' ', array_map('escapeshellarg', $this->files)) : '', $cmd);

        $null_device = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        try {
            $process = @proc_open($cmd, [
                0 => ['file', $null_device, 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $null_device, 'w']
            ], $pipes);
        } catch (\Throwable $err) {
            return false;
        }
        if (!is_resource($process)) {
            return false;
        }

        $this->begin_stream();
        $ok = true;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], self::STREAM_CHUNK_SIZE);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            if (!$this->send_chunk($chunk)) {
                $ok = false;
                break;
            }
        }
        fclose($pipes[1]);

        if (!$ok) {
            @proc_terminate($process, 9);
        }
        $rc = proc_close($process);

        return $ok && ($rc === 0 || $this->output_started);
    }

    private function php_tar(array $dirs, array $files): bool {
        $filesizes = [];
        $total_size = 512 * count($dirs);
        foreach (array_keys($files) as $real_file) {
            $size = filesize($real_file);

            $filesizes[$real_file] = $size;
            $total_size += 512 + $size;
            if ($size % 512 != 0) {
                $total_size += 512 - ($size % 512);
            }
        }

        header('Content-Length: ' . $total_size);

        $this->begin_stream();

        foreach ($dirs as $real_dir => $archived_dir) {
            if (!$this->send_chunk($this->php_tar_header($archived_dir, 0, @filemtime($real_dir . DIRECTORY_SEPARATOR . '.'), 5))) {
                return false;
            }
        }

        foreach ($files as $real_file => $archived_file) {
            $size = $filesizes[$real_file];

            if (
                !$this->send_chunk($this->php_tar_header($archived_file, $size, @filemtime($real_file), 0))
                || !$this->print_file($real_file)
            ) {
                return false;
            }

            if ($size % 512 != 0 && !$this->send_chunk(str_repeat(self::NULL_BYTE, 512 - ($size % 512)))) {
                return false;
            }
        }

        return true;
    }

    private function php_tar_header(string $filename, int $size, $mtime, int $type): string {
        if (str_starts_with($filename, './')) {
            $filename = substr($filename, 2);
        }
        $name = substr(basename($filename), -99);
        $prefix = substr(Util::normalize_path(dirname($filename)), -154);
        if ($prefix === '.') {
            $prefix = '';
        }

        $header =
            str_pad($name, 100, self::NULL_BYTE)  // filename [100]
            . '0000755' . self::NULL_BYTE  // file mode [8]
            . '0000000' . self::NULL_BYTE  // uid [8]
            . '0000000' . self::NULL_BYTE  // gid [8]
            . str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . self::NULL_BYTE  // file size [12]
            . str_pad(decoct($mtime), 11, '0', STR_PAD_LEFT) . self::NULL_BYTE  // file modification time [12]
            . '        '  // checksum [8]
            . str_pad($type, 1)  // file type [1]
            . str_repeat(self::NULL_BYTE, 100)  // linkname [100]
            . 'ustar' . self::NULL_BYTE  // magic [6]
            . '00'  // version [2]
            . str_repeat(self::NULL_BYTE, 80)  // uname, gname, defmajor, devminor [32 + 32 + 8 + 8]
            . str_pad($prefix, 155, self::NULL_BYTE)  // filename [155]
            . str_repeat(self::NULL_BYTE, 12);  // fill [12]
        assert(strlen($header) === 512);

        // checksum
        $checksum = array_sum(array_map('ord', str_split($header)));
        $checksum = str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . self::NULL_BYTE . ' ';
        $header = substr_replace($header, $checksum, 148, 8);

        return $header;
    }

    private function print_file(string $file): bool {
        // Send file content in small segments to not hit PHP's memory limit and
        // to check the client's reading speed regularly
        $fd = @fopen($file, 'rb');
        if ($fd === false) {
            return false;
        }
        $ok = true;
        while (!feof($fd)) {
            $chunk = fread($fd, self::STREAM_CHUNK_SIZE);
            if ($chunk === false || !$this->send_chunk($chunk)) {
                $ok = false;
                break;
            }
        }
        fclose($fd);
        return $ok;
    }

    /**
     * Adds the selected entries and returns the number of non-empty hrefs
     * the client sent. Entries are archived with their path relative to the
     * base folder; entries outside of it are rejected, so archive entries
     * never contain absolute server paths or "..".
     */
    private function add_hrefs($hrefs): int {
        if (!is_array($hrefs)) {
            $hrefs = [$hrefs];
        }

        $base_prefix = Util::normalize_path($this->base_path, true);
        $requested = 0;

        foreach ($hrefs as $href) {
            if (!is_string($href) || trim($href) === '') {
                continue;
            }
            $requested += 1;

            if (self::has_dot_segments($href)) {
                continue;
            }

            $href = Util::normalize_path($href, false);
            $d = dirname($href);
            $n = basename($href);

            if (!$this->context->is_managed_href($d) || $this->context->is_hidden($n)) {
                continue;
            }

            $real_file = $this->context->to_path($href);
            if (!str_starts_with($real_file, $base_prefix)) {
                continue;
            }
            $archived_file = substr($real_file, strlen($base_prefix));
            if (!self::is_safe_archive_name($archived_file)) {
                continue;
            }

            if (is_dir($real_file)) {
                $this->add_dir($real_file, $archived_file, 0);
            } else {
                $this->add_file($real_file, $archived_file);
            }
        }

        return $requested;
    }

    /**
     * True if the (raw or url-encoded) href contains "." or ".." segments.
     */
    private static function has_dot_segments(string $href): bool {
        foreach (preg_split('#[\\\\/]+#', rawurldecode($href)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }
        return false;
    }

    /**
     * Archive entry names must be relative and must not leave the archive
     * root; "." is the archive root itself.
     */
    private static function is_safe_archive_name(string $name): bool {
        if ($name === '.') {
            return true;
        }
        if (
            $name === ''
            || str_contains($name, "\0")
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || preg_match('#^[A-Za-z]:#', $name)
        ) {
            return false;
        }
        $segments = explode('/', $name);
        if ($segments[0] === '.') {
            array_shift($segments);
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function add_file(string $real_file, string $archived_file): void {
        if ($this->quota_exceeded() || !self::is_safe_archive_name($archived_file)) {
            return;
        }

        // Shell archive tools consume the relative archive name after changing
        // into the base directory, so never allow them to follow a file link.
        if (is_link($real_file)) {
            return;
        }

        $source_path = $this->context->resolve_managed_file($real_file);
        if ($source_path !== null && is_readable($source_path)) {
            if (isset($this->files[$source_path])) {
                return;
            }
            $size = @filesize($source_path);
            if (
                $size === false
                || count($this->files) >= self::MAX_FILE_COUNT
                || $size > self::MAX_TOTAL_BYTES - $this->total_bytes
            ) {
                $this->aborted = true;
                return;
            }
            $this->files[$source_path] = $archived_file;
            $this->total_bytes += $size;
        }
    }

    private function add_dir(string $real_dir, string $archived_dir, int $depth): void {
        if ($this->quota_exceeded() || $depth > self::MAX_DEPTH) {
            $this->aborted = true;
            return;
        }
        if (!self::is_safe_archive_name($archived_dir)) {
            return;
        }

        $resolved_dir = $this->context->resolve_managed_path($real_dir);
        if ($resolved_dir !== null) {
            if (isset($this->dirs[$resolved_dir])) {
                return;
            }
            if (count($this->dirs) >= self::MAX_DIR_COUNT) {
                $this->aborted = true;
                return;
            }
            $this->dirs[$resolved_dir] = $archived_dir;

            $files = $this->context->read_dir($resolved_dir);
            foreach ($files as $file) {
                if ($this->quota_exceeded()) {
                    break;
                }
                $real_file = $resolved_dir . '/' . $file;
                $archived_file = $archived_dir . '/' . $file;

                if (is_dir($real_file)) {
                    $this->add_dir($real_file, $archived_file, $depth + 1);
                } else {
                    $this->add_file($real_file, $archived_file);
                }
            }
        }
    }
}
