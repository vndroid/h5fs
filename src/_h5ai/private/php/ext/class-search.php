<?php

class Search {
    public function __construct(private Context $context) {}

    public function get_paths(string $root, ?string $pattern = null, bool $ignorecase = false): array {
        $paths = [];
        if ($pattern && $this->context->is_managed_path($root)) {
            $re = Util::wrap_pattern($pattern);
            if ($ignorecase) {
                $re .= 'i';
            }
            $names = $this->context->read_dir($root);
            foreach ($names as $name) {
                $path = $root . '/' . $name;
                if (preg_match($re, @basename($path))) {
                    $paths[] = $path;
                }
                if (@is_dir($path)) {
                    $paths = array_merge($paths, $this->get_paths($path, $pattern, $ignorecase));
                }
            }
        }
        return $paths;
    }

    public function get_items(string $href, ?string $pattern = null, bool $ignorecase = false): array {
        $cache = [];
        $root = $this->context->to_path($href);
        $paths = $this->get_paths($root, $pattern, $ignorecase);
        return array_map(function ($path) use (&$cache) {
            return Item::get($this->context, $path, $cache)->to_json_object();
        }, $paths);
    }
}
