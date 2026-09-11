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
