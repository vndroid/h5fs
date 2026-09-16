<?php

class Thumb {
    private const MAX_THUMB_DIMENSION = 4096;
    private const MAX_THUMB_PIXELS = 16777216; // 4096 * 4096
    // [FMT] is the demuxer derived from the file type, forcing it (and only
    // allowing it and the file protocol) prevents ffmpeg from probing the
    // content and opening other demuxers or protocols (e.g. HLS playlists).
    private const FFMPEG_CMDV = [
        'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
        '-protocol_whitelist', 'file', '-format_whitelist', '[FMT]', '-f', '[FMT]',
        '-ss', '0:00:10', '-i', '[SRC]',
        '-an', '-frames:v', '1', '-f', 'image2', '-update', '1', '[DEST]'
    ];
    private const AVCONV_CMDV = ['avconv', '-nostdin', '-y', '-f', '[FMT]', '-ss', '0:00:10', '-i', '[SRC]', '-an', '-vframes', '1', '[DEST]'];
    // [FMT] is an explicit ImageMagick/GraphicsMagick coder, so the input is
    // never auto-detected from its content (e.g. MVG, SVG, MSL, ...).
    private const CONVERT_CMDV = ['convert', '-density', '200', '-quality', '100', '-strip', '[FMT]:[SRC][0]', 'jpg:[DEST]'];
    private const GM_CONVERT_CMDV = ['gm', 'convert', '-density', '200', '-quality', '100', '[FMT]:[SRC][0]', 'jpg:[DEST]'];
    private const TIMEOUT_CMDV = ['timeout', '-k', '5', '[SECONDS]'];
    private const CAPTURE_TIMEOUT_SECONDS = 60;

    private const DEFAULT_CATEGORY_TYPES = [
        'img' => ['img-bmp', 'img-gif', 'img-ico', 'img-jpg', 'img-png'],
        'mov' => ['vid-avi', 'vid-flv', 'vid-mkv', 'vid-mov', 'vid-mp4', 'vid-mpg', 'vid-webm'],
        'doc' => ['x-pdf', 'x-ps']
    ];
    // file type => ffmpeg demuxer
    private const MOV_FORMATS = [
        'vid-avi' => 'avi',
        'vid-flv' => 'flv',
        'vid-mkv' => 'matroska',
        'vid-mov' => 'mov',
        'vid-mp4' => 'mov',
        'vid-mpg' => 'mpeg',
        'vid-ts' => 'mpegts',
        'vid-vob' => 'mpeg',
        'vid-webm' => 'matroska',
        'vid-wmv' => 'asf'
    ];
    // file type => ImageMagick/GraphicsMagick coder
    private const DOC_FORMATS = [
        'x-pdf' => 'pdf',
        'x-ps' => 'ps',
        'x-eps' => 'eps'
    ];
    private const THUMB_CACHE = 'thumbs';

    private Setup $setup;
    private string $thumbs_path;
    private string $thumbs_href;

    public function __construct(private Context $context) {
        $this->setup = $context->get_setup();
        $this->thumbs_path = $this->setup->get('CACHE_PUB_PATH') . '/' . self::THUMB_CACHE;
        $this->thumbs_href = $this->setup->get('CACHE_PUB_HREF') . self::THUMB_CACHE;

        if (!is_dir($this->thumbs_path)) {
            @mkdir($this->thumbs_path, 0755, true);
        }
    }

    public function thumb(string $type, string $source_href, int $width, int $height): ?string {
        if (!$this->has_valid_dimensions($width, $height)) {
            return null;
        }

        $requested_path = $this->context->to_path($source_href);
        $source_path = $this->context->resolve_managed_file($requested_path);
        if (
            $source_path === null
            || $this->context->is_hidden(basename($requested_path))
        ) {
            return null;
        }

        // Never trust the type sent by the client: derive it from the name of
        // the requested entry and of the real file (for symbolic links) with
        // the server side types and thumbnail settings. Both have to agree.
        $file_type = $this->context->get_file_type(basename($source_path));
        $category = $this->get_category($file_type);
        if (
            $category === null
            || $category !== $type
            || $this->context->get_file_type(basename($requested_path)) !== $file_type
        ) {
            return null;
        }

        $capture_path = match ($category) {
            'mov' => $this->capture_mov($file_type, $source_path),
            'doc' => $this->capture_doc($file_type, $source_path),
            default => $source_path
        };

        return $this->thumb_href($capture_path, $width, $height);
    }

    private function has_valid_dimensions(int $width, int $height): bool {
        if (
            $width <= 0
            || $height < 0
            || $width > self::MAX_THUMB_DIMENSION
            || $height > self::MAX_THUMB_DIMENSION
        ) {
            return false;
        }

        // A zero height requests a proportional thumbnail and is bounded by width.
        return $height === 0 || $width * $height <= self::MAX_THUMB_PIXELS;
    }

    private function thumb_href(?string $source_path, int $width, int $height): ?string {
        if ($source_path === null || !file_exists($source_path)) {
            return null;
        }

        $name = 'thumb-' . sha1($source_path) . '-' . $width . 'x' . $height . '.jpg';
        $thumb_path = $this->thumbs_path . '/' . $name;
        $thumb_href = $this->thumbs_href . '/' . $name;

        if (!file_exists($thumb_path) || filemtime($source_path) >= filemtime($thumb_path)) {
            $image = new Image();

            $et = false;
            if ($this->setup->get('HAS_PHP_EXIF') && $this->context->query_option('thumbnails.exif', false) === true && $height != 0) {
                $et = @exif_thumbnail($source_path);
            }
            if($et !== false) {
                file_put_contents($thumb_path, $et);
                $image->set_source($thumb_path, $width, $height);
                $image->normalize_exif_orientation($source_path);
            } else {
                $image->set_source($source_path, $width, $height);
            }

            $image->thumb($width, $height);
            $image->save_dest_jpeg($thumb_path, 80);
        }

        return file_exists($thumb_path) ? $thumb_href : null;
    }

    private function get_category(string $file_type): ?string {
        foreach (self::DEFAULT_CATEGORY_TYPES as $category => $default_types) {
            $types = $this->context->query_option('thumbnails.' . $category, $default_types);
            if (is_array($types) && in_array($file_type, $types, true)) {
                return $category;
            }
        }
        return null;
    }

    private function capture_mov(string $file_type, string $source_path): ?string {
        $format = self::MOV_FORMATS[$file_type] ?? null;
        if ($format === null) {
            return null;
        }

        return match (true) {
            (bool)$this->setup->get('HAS_CMD_FFMPEG') => $this->capture(self::FFMPEG_CMDV, $source_path, $format),
            (bool)$this->setup->get('HAS_CMD_AVCONV') => $this->capture(self::AVCONV_CMDV, $source_path, $format),
            default => null
        };
    }

    private function capture_doc(string $file_type, string $source_path): ?string {
        $format = self::DOC_FORMATS[$file_type] ?? null;
        if ($format === null) {
            return null;
        }

        return match (true) {
            (bool)$this->setup->get('HAS_CMD_CONVERT') => $this->capture(self::CONVERT_CMDV, $source_path, $format),
            (bool)$this->setup->get('HAS_CMD_GM') => $this->capture(self::GM_CONVERT_CMDV, $source_path, $format),
            default => null
        };
    }

    private function capture(array $cmdv, string $source_path, string $format): ?string {
        if (!file_exists($source_path)) {
            return null;
        }

        $capture_path = $this->thumbs_path . '/capture-' . sha1($source_path) . '.jpg';

        if (!file_exists($capture_path) || filemtime($source_path) >= filemtime($capture_path)) {
            $placeholders = [
                '[FMT]' => $format,
                '[SRC]' => $source_path,
                '[DEST]' => $capture_path
            ];
            // replace all placeholders in one pass, so placeholder-like
            // sequences in file names are never substituted
            $cmdv = array_map(fn($arg) => strtr($arg, $placeholders), $cmdv);

            if (PHP_OS_FAMILY !== 'Windows' && $this->setup->get('HAS_CMD_TIMEOUT')) {
                $timeout_cmdv = array_map(
                    fn($arg) => strtr($arg, ['[SECONDS]' => (string)self::CAPTURE_TIMEOUT_SECONDS]),
                    self::TIMEOUT_CMDV
                );
                $cmdv = array_merge($timeout_cmdv, $cmdv);
            }

            Util::exec_cmdv($cmdv);
        }

        return file_exists($capture_path) ? $capture_path : null;
    }
}

class Image {
    private const MAX_SOURCE_BYTES = 33554432; // 32 MiB
    private const MAX_SOURCE_DIMENSION = 16384;
    private const MAX_SOURCE_PIXELS = 25000000;
    private const MEMORY_BYTES_PER_PIXEL = 6;
    private const MEMORY_SAFETY_BYTES = 16777216; // 16 MiB

    private $source_file;
    private $source;
    private $width;
    private $height;
    private $type;
    private $dest;

    public function __construct(?string $filename = null) {
        $this->source_file = null;
        $this->source = null;
        $this->width = null;
        $this->height = null;
        $this->type = null;

        $this->dest = null;

        $this->set_source($filename);
    }

    public function __destruct() {
        $this->release_source();
        $this->release_dest();
    }

    public function set_source(?string $filename, int $dest_width = 0, int $dest_height = 0): void {
        $this->release_source();
        $this->release_dest();

        if ($filename === null) {
            return;
        }

        $source_size = @filesize($filename);
        if (
            $source_size === false
            || $source_size <= 0
            || $source_size > self::MAX_SOURCE_BYTES
        ) {
            return;
        }

        $this->source_file = $filename;

        $imageInfo = @getimagesize($this->source_file);
        if (!$imageInfo || !$imageInfo[0] || !$imageInfo[1]) {
            $this->source_file = null;
            return;
        }
        [$this->width, $this->height, $this->type] = $imageInfo;

        $source_pixels = $this->width * $this->height;
        if (
            $this->width > self::MAX_SOURCE_DIMENSION
            || $this->height > self::MAX_SOURCE_DIMENSION
            || $source_pixels > self::MAX_SOURCE_PIXELS
            || !$this->has_memory_for_decode($source_size, $source_pixels, $dest_width, $dest_height)
        ) {
            $this->source_file = null;
            $this->width = null;
            $this->height = null;
            $this->type = null;
            return;
        }

        $imageData = @file_get_contents($this->source_file);
        $image = $imageData !== false ? imagecreatefromstring($imageData) : false;
        if ($image === false) {
            $this->source_file = null;
            $this->width = null;
            $this->height = null;
            $this->type = null;
            return;
        }
        $this->source = $image;
    }

    private function has_memory_for_decode(int $source_size, int $source_pixels, int $dest_width, int $dest_height): bool {
        $memory_limit = $this->memory_limit_bytes((string)ini_get('memory_limit'));
        if ($memory_limit === null) {
            return true;
        }

        $dest_pixels = $dest_height === 0
            ? $dest_width * $dest_width
            : $dest_width * $dest_height;
        $estimated = memory_get_usage(true)
            + $source_size
            + (($source_pixels + $dest_pixels) * self::MEMORY_BYTES_PER_PIXEL)
            + self::MEMORY_SAFETY_BYTES;
        return $estimated <= $memory_limit;
    }

    private function memory_limit_bytes(string $value): ?int {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int)$value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number
        };
    }

    public function save_dest_jpeg(string $filename, int $quality = 80): void {
        if ($this->dest !== null) {
            @imagejpeg($this->dest, $filename, $quality);
            @chmod($filename, 0775);
        }
    }

    public function release_dest(): void {
        if ($this->dest !== null) {
            $this->dest = null;
        }
    }

    public function release_source(): void {
        if ($this->source !== null) {
            $this->source_file = null;
            $this->source = null;
            $this->width = null;
            $this->height = null;
            $this->type = null;
        }
    }

    public function thumb(int $width, int $height): void {
        if ($this->source === null) {
            return;
        }

        $src_r = 1.0 * $this->width / $this->height;

        if ($height == 0) {
            if ($src_r >= 1) {
                $height = 1.0 * $width / $src_r;
            } else {
                $height = $width;
                $width = 1.0 * $height * $src_r;
            }
            if ($width > $this->width) {
                $width = $this->width;
                $height = $this->height;
            }
        }

        $ratio = 1.0 * $width / $height;

        if ($src_r <= $ratio) {
            $src_w = $this->width;
            $src_h = $src_w / $ratio;
            $src_x = 0;
        } else {
            $src_h = $this->height;
            $src_w = $src_h * $ratio;
            $src_x = 0.5 * ($this->width - $src_w);
        }

        $width = intval($width);
        $height = intval($height);
        $src_x = intval($src_x);
        $src_w = intval($src_w);
        $src_h = intval($src_h);

        $this->dest = imagecreatetruecolor($width, $height);
        $icol = imagecolorallocate($this->dest, 255, 255, 255);
        imagefill($this->dest, 0, 0, $icol);
        imagecopyresampled($this->dest, $this->source, 0, 0, $src_x, 0, $width, $height, $src_w, $src_h);
    }

    public function rotate(int $angle): void {
        if ($this->source === null || !in_array($angle, [90, 180, 270], true)) {
            return;
        }

        $rotated = imagerotate($this->source, $angle, 0);
        if ($rotated !== false) {
            $this->source = $rotated;
        }
        if ($angle === 90 || $angle === 270) {
            [$this->width, $this->height] = [$this->height, $this->width];
        }
    }

    public function normalize_exif_orientation(?string $exif_source_file = null): void {
        if ($this->source === null || !function_exists('exif_read_data')) {
            return;
        }

        $exif_source_file ??= $this->source_file;

        $exif = exif_read_data($exif_source_file);
        match ($exif !== false ? ($exif['Orientation'] ?? null) : null) {
            3 => $this->rotate(180),
            6 => $this->rotate(270),
            8 => $this->rotate(90),
            default => null
        };
    }
}
