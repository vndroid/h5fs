<?php

class Archive {
    private const NULL_BYTE = "\0";
    private const SEGMENT_SIZE = 16777216;  // 1024 * 1024 * 16 = 16MiB
    private const TAR_PASSTHRU_CMD = 'cd [ROOTDIR] && tar --no-recursion -c -- [DIRS] [FILES]';
    private const ZIP_PASSTHRU_CMD = 'cd [ROOTDIR] && zip - -- [FILES]';

    private string $base_path;
    private array $dirs = [];
    private array $files = [];

    public function __construct(private Context $context) {}

    public function output(string $type, string $base_href, $hrefs): bool {
        $this->base_path = $this->context->to_path($base_href);
        if (!$this->context->is_managed_path($this->base_path)) {
            return false;
        }

        $this->dirs = [];
        $this->files = [];

        $this->add_hrefs($hrefs);

        if (count($this->dirs) === 0 && count($this->files) === 0) {
            if ($type === 'php-tar') {
                $this->add_dir($this->base_path, '/');
            } else {
                $this->add_dir($this->base_path, '.');
            }
        }

        return match ($type) {
            'php-tar' => $this->php_tar($this->dirs, $this->files),
            'shell-tar' => $this->shell_cmd(self::TAR_PASSTHRU_CMD),
            'shell-zip' => $this->shell_cmd(self::ZIP_PASSTHRU_CMD),
            default => false
        };
    }

    private function shell_cmd(string $cmd): bool {
        $cmd = str_replace('[ROOTDIR]', escapeshellarg($this->base_path), $cmd);
        $cmd = str_replace('[DIRS]', count($this->dirs) ? implode(' ', array_map('escapeshellarg', $this->dirs)) : '', $cmd);
        $cmd = str_replace('[FILES]', count($this->files) ? implode(' ', array_map('escapeshellarg', $this->files)) : '', $cmd);
        try {
            Util::passthru_cmd($cmd);
        } catch (\Throwable $err) {
            return false;
        }
        return true;
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

        foreach ($dirs as $real_dir => $archived_dir) {
            echo $this->php_tar_header($archived_dir, 0, @filemtime($real_dir . DIRECTORY_SEPARATOR . '.'), 5);
        }

        foreach ($files as $real_file => $archived_file) {
            $size = $filesizes[$real_file];

            echo $this->php_tar_header($archived_file, $size, @filemtime($real_file), 0);
            $this->print_file($real_file);

            if ($size % 512 != 0) {
                echo str_repeat(self::NULL_BYTE, 512 - ($size % 512));
            }
        }

        return true;
    }

    private function php_tar_header(string $filename, int $size, $mtime, int $type): string {
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

    private function print_file(string $file): void {
        // Send file content in segments to not hit PHP's memory limit (default: 128M)
        if ($fd = fopen($file, 'rb')) {
            while (!feof($fd)) {
                print fread($fd, self::SEGMENT_SIZE);
                @ob_flush();
                @flush();
            }
            fclose($fd);
        }
    }

    private function add_hrefs($hrefs): void {
        if (!is_array($hrefs)) {
            $hrefs = [$hrefs];
        }

        foreach ($hrefs as $href) {
            if (trim($href) === '') {
                continue;
            }

            $href = Util::normalize_path($href, false);
            $d = dirname($href);
            $n = basename($href);

            if ($this->context->is_managed_href($d) && !$this->context->is_hidden($n)) {

                $real_file = $this->context->to_path($href);
                $archived_file = preg_replace('!^' . preg_quote(Util::normalize_path($this->base_path, true)) . '!', '', $real_file);

                if (is_dir($real_file)) {
                    $this->add_dir($real_file, $archived_file);
                } else {
                    $this->add_file($real_file, $archived_file);
                }
            }
        }
    }

    private function add_file(string $real_file, string $archived_file): void {
        if (is_readable($real_file)) {
            $this->files[$real_file] = $archived_file;
        }
    }

    private function add_dir(string $real_dir, string $archived_dir): void {
        if ($this->context->is_managed_path($real_dir)) {
            $this->dirs[$real_dir] = $archived_dir;

            $files = $this->context->read_dir($real_dir);
            foreach ($files as $file) {
                $real_file = $real_dir . '/' . $file;
                $archived_file = $archived_dir . '/' . $file;

                if (is_dir($real_file)) {
                    $this->add_dir($real_file, $archived_file);
                } else {
                    $this->add_file($real_file, $archived_file);
                }
            }
        }
    }
}
