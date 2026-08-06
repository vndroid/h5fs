<?php

class Theme {
    private const EXTENSIONS = ['svg', 'png', 'jpg'];

    public function __construct(private Context $context) {}

    public function get_icons(): array {
        $public_path = $this->context->get_setup()->get('PUBLIC_PATH');
        $theme = $this->context->query_option('view.theme', '-NONE-');
        $theme_path = $public_path . '/images/themes/' . $theme;

        $icons = [];

        if (is_dir($theme_path)) {
            if ($dir = opendir($theme_path)) {
                while (($name = readdir($dir)) !== false) {
                    $path_parts = pathinfo($name);
                    if (in_array($path_parts['extension'] ?? null, self::EXTENSIONS, true)) {
                        $icons[$path_parts['filename']] = $theme . '/' . $name;
                    }
                }
                closedir($dir);
            }
        }

        return $icons;
    }
}
