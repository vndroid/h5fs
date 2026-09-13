<?php

readonly class Search {
    private const MAX_PATTERN_LENGTH = 256;
    private const MAX_VISITED_ENTRIES = 10000;
    private const MAX_DEPTH = 32;
    private const MAX_DURATION_NS = 2000000000; // 2 seconds
    private const PCRE_BACKTRACK_LIMIT = 100000;
    private const PCRE_RECURSION_LIMIT = 10000;

    public function __construct(private Context $context) {}

    public function get_paths(string $root, ?string $pattern = null, bool $ignorecase = false): array {
        if (!$pattern || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return [];
        }

        $root = $this->context->resolve_managed_path($root);
        if ($root === null) {
            return [];
        }

        $re = Util::wrap_pattern($pattern) . ($ignorecase ? 'i' : '');
        if (@preg_match($re, '') === false) {
            return [];
        }

        $old_backtrack_limit = ini_get('pcre.backtrack_limit');
        $old_recursion_limit = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', (string)self::PCRE_BACKTRACK_LIMIT);
        ini_set('pcre.recursion_limit', (string)self::PCRE_RECURSION_LIMIT);

        $state = [
            'aborted' => false,
            'visited' => 0,
            'directories' => [],
            'deadline' => hrtime(true) + self::MAX_DURATION_NS
        ];

        try {
            return $this->search_paths($root, $re, 0, $state);
        } finally {
            ini_set('pcre.backtrack_limit', (string)$old_backtrack_limit);
            ini_set('pcre.recursion_limit', (string)$old_recursion_limit);
        }
    }

    private function search_paths(string $root, string $re, int $depth, array &$state): array {
        if (
            $state['aborted']
            || $depth > self::MAX_DEPTH
            || $state['visited'] >= self::MAX_VISITED_ENTRIES
            || hrtime(true) >= $state['deadline']
        ) {
            $state['aborted'] = true;
            return [];
        }

        $root = $this->context->resolve_managed_path($root);
        if ($root === null || isset($state['directories'][$root])) {
            return [];
        }
        $state['directories'][$root] = true;

        $paths = [];
        foreach ($this->context->read_dir($root) as $name) {
            $state['visited'] += 1;
            if (
                $state['visited'] > self::MAX_VISITED_ENTRIES
                || hrtime(true) >= $state['deadline']
            ) {
                $state['aborted'] = true;
                break;
            }

            $path = $root . '/' . $name;
            $matched = @preg_match($re, $name);
            if ($matched === false || preg_last_error() !== PREG_NO_ERROR) {
                $state['aborted'] = true;
                break;
            }
            if ($matched === 1) {
                $paths[] = $path;
            }

            if (@is_dir($path)) {
                foreach ($this->search_paths($path, $re, $depth + 1, $state) as $matched_path) {
                    $paths[] = $matched_path;
                }
            }
            if ($state['aborted']) {
                break;
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
