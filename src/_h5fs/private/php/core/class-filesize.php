<?php

class Filesize {
    private static array $cache = [];

    public static function getSize(string $path, bool $withFoldersize, bool $withDu) {
        $fs = new Filesize();
        return $fs->size($path, $withFoldersize, $withDu);
    }

    public static function getCachedSize(string $path, bool $withFoldersize, bool $withDu) {
        if (array_key_exists($path, Filesize::$cache)) {
            return Filesize::$cache[$path];
        }

        $size = Filesize::getSize($path, $withFoldersize, $withDu);

        Filesize::$cache[$path] = $size;
        return $size;
    }


    private function __construct() {}

    private function read_dir(string $path): array {
        $paths = [];
        if (is_dir($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $paths[] = $path . '/' . $name;
                }
            }
        }
        return $paths;
    }

    private function php_filesize(string $path, bool $recursive = false) {
        $size = @filesize($path);

        if (!is_dir($path) || !$recursive) {
            return $size;
        }

        foreach ($this->read_dir($path) as $p) {
            $size += $this->php_filesize($p, true);
        }
        return $size;
    }


    private function exec(array $cmdv): array {
        $cmd = implode(' ', array_map('escapeshellarg', $cmdv));
        $lines = [];
        $rc = null;
        exec($cmd, $lines, $rc);
        return $lines;
    }

    private function exec_du_all(array $paths): array {
        $cmdv = ['du', '-sbL', ...$paths];
        $lines = $this->exec($cmdv);

        $sizes = [];
        foreach ($lines as $line) {
            [$size, $path] = preg_split('/[\s]+/', $line, 2);
            $sizes[$path] = (int)$size;
        }
        return $sizes;
    }

    private function exec_du(string $path): ?int {
        $sizes = $this->exec_du_all([$path]);
        return $sizes[$path] ?? null;
    }


    private function size(string $path, bool $withFoldersize = false, bool $withDu = false) {
        if (is_file($path)) {
            return $this->php_filesize($path);
        }

        if (is_dir($path) && $withFoldersize) {
            if ($withDu) {
                return $this->exec_du($path);
            }

            return $this->php_filesize($path, true);
        }

        return null;
    }
}
