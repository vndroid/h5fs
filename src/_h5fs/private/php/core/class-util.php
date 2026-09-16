<?php

class Util {
    public const ERR_MISSING_PARAM = 'ERR_MISSING_PARAM';
    public const ERR_ILLIGAL_PARAM = 'ERR_ILLIGAL_PARAM';
    public const ERR_FAILED = 'ERR_FAILED';
    public const ERR_DISABLED = 'ERR_DISABLED';
    public const ERR_UNSUPPORTED = 'ERR_UNSUPPORTED';
    public const NO_DEFAULT = 'NO_*@+#?!_DEFAULT';
    public const RE_DELIMITER = '@';

    public static function normalize_path(string $path, bool $trailing_slash = false): string {
        $path = preg_replace('#[\\\\/]+#', '/', $path);
        return preg_match('#^(\w:)?/$#', $path) ? $path : (rtrim($path, '/') . ($trailing_slash ? '/' : ''));
    }

    /**
     * Cleans an untrusted file name: invalid UTF-8, control and format
     * characters (e.g. RTL overrides) are removed, path separators replaced,
     * leading/trailing dots and spaces trimmed and the length is limited.
     */
    public static function sanitize_filename(string $name, string $fallback = 'download'): string {
        if (preg_match('//u', $name) !== 1) {
            $name = function_exists('mb_scrub') ? mb_scrub($name, 'UTF-8') : preg_replace('/[\x80-\xFF]/', '', $name);
        }
        $name = preg_replace('/[\x00-\x1F\x7F]|\p{Cc}|\p{Cf}|\p{Zl}|\p{Zp}/u', '', $name) ?? '';
        $name = str_replace(['/', '\\'], '_', $name);
        $name = trim($name, " .\t");
        if (preg_match('/^.{0,200}/us', $name, $matches) === 1) {
            $name = $matches[0];
        }
        return $name === '' ? $fallback : $name;
    }

    /**
     * Builds a safe `Content-Disposition: attachment` header value: a quoted
     * plain ASCII fallback (no quotes, backslashes, `%` or `;`) plus the
     * UTF-8 name as RFC 6266/5987 `filename*`.
     */
    public static function content_disposition_attachment(string $name): string {
        $name = Util::sanitize_filename($name);
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\%;]/', '_', $name);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /**
     * Identifies a client by its address; IPv6 clients are grouped by /64,
     * since a single client usually controls a whole /64.
     */
    public static function client_id(string $addr): string {
        $packed = @inet_pton($addr);
        if ($packed !== false && strlen($packed) === 16) {
            return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $addr;
    }

    public static function json_exit(array $obj = []): void {
        header('Content-type: application/json;charset=utf-8');
        echo json_encode($obj);
        exit;
    }

    public static function json_fail(string $err, string $msg = '', bool $cond = true): void {
        if ($cond) {
            Util::json_exit(['err' => $err, 'msg' => $msg]);
        }
    }

    public static function array_query($array, string $keypath = '', $default = Util::NO_DEFAULT) {
        $value = $array;

        $keys = array_filter(explode('.', $keypath));
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public static function wrap_pattern(string $pattern): string {
        return Util::RE_DELIMITER . str_replace(Util::RE_DELIMITER, '\\' . Util::RE_DELIMITER, $pattern) . Util::RE_DELIMITER;
    }

    public static function passthru_cmd(string $cmd): ?int {
        $rc = null;
        passthru($cmd, $rc);
        return $rc;
    }

    public static function exec_cmdv(...$cmdv): string {
        if (count($cmdv) === 1 && is_array($cmdv[0])) {
            $cmdv = $cmdv[0];
        }
        $cmd = implode(' ', array_map('escapeshellarg', $cmdv));

        $lines = [];
        $rc = null;
        exec($cmd, $lines, $rc);
        return implode("\n", $lines);
    }

    public static function exec_0(string $cmd): bool {
        $lines = [];
        $rc = null;
        try {
            @exec($cmd, $lines, $rc);
            return $rc === 0;
        } catch (\Throwable $e) {}
        return false;
    }

    public static function filesize(Context $context, string $path) {
        $withFoldersize = $context->query_option('foldersize.enabled', false);
        $withDu = $context->get_setup()->get('HAS_CMD_DU') && $context->query_option('foldersize.type', null) === 'shell-du';
        return Filesize::getCachedSize($path, $withFoldersize, $withDu);
    }
}
