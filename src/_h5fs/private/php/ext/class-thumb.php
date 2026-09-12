<?php

class Thumb {
    private const MAX_THUMB_DIMENSION = 4096;
    private const MAX_THUMB_PIXELS = 16777216; // 4096 * 4096
    private const FFMPEG_CMDV = ['ffmpeg', '-ss', '0:00:10', '-i', '[SRC]', '-an', '-vframes', '1', '[DEST]'];
    private const AVCONV_CMDV = ['avconv', '-ss', '0:00:10', '-i', '[SRC]', '-an', '-vframes', '1', '[DEST]'];
    private const CONVERT_CMDV = ['convert', '-density', '200', '-quality', '100', '-strip', '[SRC][0]', '[DEST]'];
    private const GM_CONVERT_CMDV = ['gm', 'convert', '-density', '200', '-quality', '100', '[SRC][0]', '[DEST]'];
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
        $source_path = realpath($requested_path);
        if (
            $source_path === false
            || $this->context->is_hidden(basename($requested_path))
            || !$this->context->is_managed_file($source_path)
        ) {
            return null;
        }

        $capture_path = match ($type) {
            'mov' => match (true) {
                (bool)$this->setup->get('HAS_CMD_AVCONV') => $this->capture(self::AVCONV_CMDV, $source_path),
                (bool)$this->setup->get('HAS_CMD_FFMPEG') => $this->capture(self::FFMPEG_CMDV, $source_path),
                default => $source_path
            },
            'doc' => match (true) {
                (bool)$this->setup->get('HAS_CMD_CONVERT') => $this->capture(self::CONVERT_CMDV, $source_path),
                (bool)$this->setup->get('HAS_CMD_GM') => $this->capture(self::GM_CONVERT_CMDV, $source_path),
                default => $source_path
            },
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
                $image->set_source($thumb_path);
                $image->normalize_exif_orientation($source_path);
            } else {
                $image->set_source($source_path);
            }

            $image->thumb($width, $height);
            $image->save_dest_jpeg($thumb_path, 80);
        }

        return file_exists($thumb_path) ? $thumb_href : null;
    }

    private function capture(array $cmdv, string $source_path): ?string {
        if (!file_exists($source_path)) {
            return null;
        }

        $capture_path = $this->thumbs_path . '/capture-' . sha1($source_path) . '.jpg';

        if (!file_exists($capture_path) || filemtime($source_path) >= filemtime($capture_path)) {
            foreach ($cmdv as &$arg) {
                $arg = str_replace('[SRC]', $source_path, $arg);
                $arg = str_replace('[DEST]', $capture_path, $arg);
            }

            Util::exec_cmdv($cmdv);
        }

        return file_exists($capture_path) ? $capture_path : null;
    }
}

class Image {
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

    public function set_source(?string $filename): void {
        $this->release_source();
        $this->release_dest();

        if ($filename === null) {
            return;
        }

        $this->source_file = $filename;

        $imageInfo = @getimagesize($this->source_file);
        if (!$imageInfo || !$imageInfo[0] || !$imageInfo[1]) {
            $this->source_file = null;
            return;
        }
        [$this->width, $this->height, $this->type] = $imageInfo;

        $imageData = file_get_contents($this->source_file);
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
