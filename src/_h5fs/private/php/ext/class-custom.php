<?php

readonly class Custom {
    private const EXTENSIONS = ['html', 'md'];

    public function __construct(private Context $context) {}

    private function read_custom_file(string $path, string $name, &$content, &$type): void {
        $file_prefix = $this->context->get_setup()->get('FILE_PREFIX');

        foreach (self::EXTENSIONS as $ext) {
            $file_name = $file_prefix . '.' . $name . '.' . $ext;
            $file = $this->resolve_custom_file($path . '/' . $file_name, $file_name);
            if ($file !== null) {
                $data = @file_get_contents($file);
                if ($data !== false) {
                    $content = $data;
                    $type = $ext;
                    return;
                }
            }
        }
    }

    /**
     * Resolves a custom file (which might be a symbolic link) and only
     * returns it if the real file is inside a managed folder. The target
     * must either be a custom file itself (those are usually hidden by the
     * "^_h5fs" rule) or a regular visible file, so links can't be used to
     * read files outside of the root or hidden files and folders.
     */
    private function resolve_custom_file(string $file, string $file_name): ?string {
        $real_file = realpath($file);
        if ($real_file === false || !is_file($real_file) || !is_readable($real_file)) {
            return null;
        }
        $real_file = Util::normalize_path($real_file);

        if (basename($real_file) === $file_name) {
            return $this->context->resolve_managed_path(dirname($real_file)) !== null ? $real_file : null;
        }

        return $this->context->resolve_managed_file($real_file);
    }

    public function get_customizations(string $href): array {
        if (!$this->context->query_option('custom.enabled', false)) {
            return [
                'header' => ['content' => null, 'type' => null],
                'footer' => ['content' => null, 'type' => null]
            ];
        }

        $header = null;
        $header_type = null;
        $footer = null;
        $footer_type = null;

        $path = $this->context->resolve_managed_path($this->context->to_path($href));
        if ($path === null) {
            return [
                'header' => ['content' => $header, 'type' => $header_type],
                'footer' => ['content' => $footer, 'type' => $footer_type]
            ];
        }

        $root_path = Util::normalize_path(realpath($this->context->get_setup()->get('ROOT_PATH')));

        $this->read_custom_file($path, 'header', $header, $header_type);
        $this->read_custom_file($path, 'footer', $footer, $footer_type);

        while ($header === null || $footer === null) {
            if ($header === null) {
                $this->read_custom_file($path, 'headers', $header, $header_type);
            }
            if ($footer === null) {
                $this->read_custom_file($path, 'footers', $footer, $footer_type);
            }
            if ($path === $root_path) {
                break;
            }
            $parent_path = Util::normalize_path(dirname($path));
            if ($parent_path === $path) {
                break;
            }

            // Stop once we reach the root
            if (
                $this->context->query_option('custom.stopSearchingAtRoot', true) &&
                $path === $this->context->get_setup()->get('ROOT_PATH')
            ) {
                break;
            }
            $path = $parent_path;
        }

        return [
            'header' => ['content' => $header, 'type' => $header_type],
            'footer' => ['content' => $footer, 'type' => $footer_type]
        ];
    }
}
