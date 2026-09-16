<?php

class Context {
    private const MAX_THUMB_REQUESTS = 40;
    private const DEFAULT_PASSHASH = 'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e';
    private const AS_ADMIN_SESSION_KEY = 'AS_ADMIN';
    private const L10N_ISO_CODES = [
        'af', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'he',
        'hi', 'hr', 'hu', 'id', 'it', 'ja','ko', 'lv', 'nb', 'nl', 'pl',
        'pt-br', 'pt-pt', 'ro', 'ru', 'sk', 'sl', 'sr', 'sv', 'tr', 'uk',
        'zh-cn', 'zh-tw'
    ];

    private array $options;
    private string $passhash;
    private ?array $type_regexps = null;

    public function __construct(
        private Session $session,
        private Request $request,
        private Setup $setup
    ) {
        $this->options = Json::load($this->setup->get('CONF_PATH') . '/options.json');

        $this->passhash = $this->query_option('passhash', '');
        $this->options['hasCustomPasshash'] = strcasecmp($this->passhash, self::DEFAULT_PASSHASH) !== 0;
        unset($this->options['passhash']);
    }

    public function get_session(): Session {
        return $this->session;
    }

    public function get_request(): Request {
        return $this->request;
    }

    public function get_setup(): Setup {
        return $this->setup;
    }

    public function get_options(): array {
        return $this->options;
    }

    public function query_option(string $keypath = '', mixed $default = null): mixed {
        return Util::array_query($this->options, $keypath, $default);
    }

    public function get_types(): array {
        return Json::load($this->setup->get('CONF_PATH') . '/types.json');
    }

    /**
     * Server side equivalent of the client's `types.getType()`: matches a
     * file name against the glob patterns of "types.json" (case insensitive,
     * the last matching type wins).
     */
    public function get_file_type(string $name): string {
        if ($this->type_regexps === null) {
            $this->type_regexps = [];
            foreach ($this->get_types() as $type => $patterns) {
                if (!is_string($type) || !is_array($patterns) || count($patterns) === 0) {
                    continue;
                }
                $parts = [];
                foreach ($patterns as $pattern) {
                    if (is_string($pattern)) {
                        $parts[] = '(' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . ')';
                    }
                }
                if (count($parts) > 0) {
                    $this->type_regexps[$type] = '/^(' . implode('|', $parts) . ')$/is';
                }
            }
        }

        $result = 'file';
        foreach ($this->type_regexps as $type => $regexp) {
            if (preg_match($regexp, $name) === 1) {
                $result = $type;
            }
        }
        return $result;
    }

    public function login_admin(#[\SensitiveParameter] string $pass): bool {
        $this->session->set(self::AS_ADMIN_SESSION_KEY, strcasecmp(hash('sha512', $pass), $this->passhash) === 0);
        return $this->session->get(self::AS_ADMIN_SESSION_KEY);
    }

    public function logout_admin(): bool {
        $this->session->set(self::AS_ADMIN_SESSION_KEY, false);
        return $this->session->get(self::AS_ADMIN_SESSION_KEY);
    }

    public function is_admin(): bool {
        return (bool)$this->session->get(self::AS_ADMIN_SESSION_KEY);
    }

    public function is_api_request(): bool {
        return strtolower($this->setup->get('REQUEST_METHOD')) === 'post';
    }

    public function is_info_request(): bool {
        return str_starts_with($this->setup->get('REQUEST_HREF') . '/', $this->setup->get('PUBLIC_HREF'));
    }

    public function is_text_browser(): bool {
        return preg_match('/curl|links|lynx|w3m/i', $this->setup->get('HTTP_USER_AGENT')) === 1;
    }

    public function is_fallback_mode(): bool {
        return $this->query_option('view.fallbackMode', false) || $this->is_text_browser();
    }

    public function to_href(string $path, bool $trailing_slash = true): string {
        $rel_path = substr($path, strlen($this->setup->get('ROOT_PATH')));
        $parts = explode('/', $rel_path);
        $encoded_parts = [];
        foreach ($parts as $part) {
            if ($part != '') {
                $encoded_parts[] = rawurlencode($part);
            }
        }

        return Util::normalize_path($this->setup->get('ROOT_HREF') . implode('/', $encoded_parts), $trailing_slash);
    }

    public function to_path(string $href): string {
        $rel_href = substr($href, strlen($this->setup->get('ROOT_HREF')));
        return Util::normalize_path($this->setup->get('ROOT_PATH') . '/' . rawurldecode($rel_href));
    }

    public function is_hidden(string $name): bool {
        // always hide
        if ($name === '.' || $name === '..') {
            return true;
        }

        foreach ($this->query_option('view.hidden', []) as $re) {
            $re = Util::wrap_pattern($re);
            if (preg_match($re, $name)) {
                return true;
            }
        }

        return false;
    }

    public function read_dir(string $path): array {
        $names = [];
        if (is_dir($path)) {
            foreach (scandir($path) as $name) {
                if (
                    $this->is_hidden($name)
                    || $this->is_hidden($this->to_href($path) . $name)
                    || (!is_readable($path . '/' . $name) && $this->query_option('view.hideIf403', false))
                ) {
                    continue;
                }
                $names[] = $name;
            }
        }
        return $names;
    }

    public function is_managed_href(string $href): bool {
        return $this->is_managed_path($this->to_path($href));
    }

    public function is_managed_file(string $path): bool {
        return $this->resolve_managed_file($path) !== null;
    }

    public function resolve_managed_file(string $path): ?string {
        $path = realpath($path);
        if (
            $path === false
            || !is_file($path)
        ) {
            return null;
        }

        $path = Util::normalize_path($path);
        $parent_path = $this->resolve_managed_path(dirname($path));
        if (
            $parent_path === null
            || $this->is_hidden_entry($parent_path, basename($path))
        ) {
            return null;
        }

        return $path;
    }

    /**
     * Applies the same `view.hidden` rules as `read_dir()` to a single entry:
     * the plain name and the href of the entry are both matched.
     */
    private function is_hidden_entry(string $resolved_parent, string $name, ?string $root_path = null): bool {
        if ($this->is_hidden($name)) {
            return true;
        }

        $parent_href = $this->resolved_path_to_href($resolved_parent, $root_path);
        return $parent_href !== null && $this->is_hidden($parent_href . $name);
    }

    /**
     * Like `to_href()`, but for a canonical path produced by `realpath()`,
     * which may not share a textual prefix with the configured ROOT_PATH.
     */
    private function resolved_path_to_href(string $resolved_path, ?string $root_path = null): ?string {
        if ($root_path === null) {
            $root_path = realpath($this->setup->get('ROOT_PATH'));
            if ($root_path === false) {
                return null;
            }
            $root_path = Util::normalize_path($root_path);
        }
        if (!$this->is_path_within($resolved_path, $root_path)) {
            return null;
        }

        $rel_path = substr($resolved_path, strlen(rtrim($root_path, '/')));
        $encoded_parts = [];
        foreach (explode('/', $rel_path) as $part) {
            if ($part !== '') {
                $encoded_parts[] = rawurlencode($part);
            }
        }

        return Util::normalize_path($this->setup->get('ROOT_HREF') . implode('/', $encoded_parts), true);
    }

    private function is_path_within(string $path, string $parent): bool {
        return $path === $parent || str_starts_with($path, rtrim($parent, '/') . '/');
    }

    public function is_managed_path(string $path): bool {
        return $this->resolve_managed_path($path) !== null;
    }

    public function resolve_managed_path(string $path): ?string {
        $path = realpath($path);
        $root_path = realpath($this->setup->get('ROOT_PATH'));
        $public_path = realpath($this->setup->get('PUBLIC_PATH'));
        $private_path = realpath($this->setup->get('PRIVATE_PATH'));

        if ($path === false || $root_path === false || !is_dir($path)) {
            return null;
        }

        $path = Util::normalize_path($path);
        $root_path = Util::normalize_path($root_path);

        if (!$this->is_path_within($path, $root_path)) {
            return null;
        }

        if (
            $public_path !== false
            && $this->is_path_within($path, Util::normalize_path($public_path))
        ) {
            return null;
        }

        if (
            $private_path !== false
            && $this->is_path_within($path, Util::normalize_path($private_path))
        ) {
            return null;
        }

        foreach ($this->query_option('view.unmanaged', []) as $name) {
            if (file_exists($path . '/' . $name)) {
                return null;
            }
        }

        $managed_path = $path;

        while ($path !== $root_path) {
            if (@is_dir($path . '/_h5fs/private/conf')) {
                return null;
            }
            $parent_path = Util::normalize_path(dirname($path));
            if ($parent_path === $path) {
                return null;
            }
            // A folder is only managed if none of its ancestors (below the
            // root) is hidden, otherwise hidden folders could still be read
            // by requesting a path inside of them directly.
            if ($this->is_hidden_entry($parent_path, basename($path), $root_path)) {
                return null;
            }
            $path = $parent_path;
        }
        return $managed_path;
    }

    public function get_current_path(): string {
        $current_href = Util::normalize_path($this->setup->get('REQUEST_HREF'), true);
        $current_path = $this->to_path($current_href);

        if (!is_dir($current_path)) {
            $current_path = Util::normalize_path(dirname($current_path), false);
        }

        return $current_path;
    }

    public function get_items(string $href, int $what): array {
        if (!$this->is_managed_href($href)) {
            return [];
        }

        $cache = [];
        $folder = Item::get($this, $this->to_path($href), $cache);

        // add content of subfolders
        if ($what >= 2 && $folder !== null) {
            foreach ($folder->get_content($cache) as $item) {
                $item->get_content($cache);
            }
            $folder = $folder->get_parent($cache);
        }

        // add content of this folder and all parent folders
        while ($what >= 1 && $folder !== null) {
            $folder->get_content($cache);
            $folder = $folder->get_parent($cache);
        }

        uasort($cache, ['Item', 'cmp']);
        $result = [];
        foreach ($cache as $p => $item) {
            $result[] = $item->to_json_object();
        }

        return $result;
    }

    public function get_langs(): array {
        $langs = [];
        $l10n_path = $this->setup->get('CONF_PATH') . '/l10n';
        if (is_dir($l10n_path)) {
            if ($dir = opendir($l10n_path)) {
                while (($file = readdir($dir)) !== false) {
                    if (str_ends_with($file, '.json')) {
                        $translations = Json::load($l10n_path . '/' . $file);
                        $langs[basename($file, '.json')] = $translations['lang'];
                    }
                }
                closedir($dir);
            }
        }
        ksort($langs);
        return $langs;
    }

    public function get_l10n(array $iso_codes): array {
        $results = [];

        foreach ($iso_codes as $iso_code) {
            if (!in_array($iso_code, self::L10N_ISO_CODES, true)) {
                continue;
            }

            $file = $this->setup->get('CONF_PATH') . '/l10n/' . $iso_code . '.json';
            $results[$iso_code] = Json::load($file);
            $results[$iso_code]['isoCode'] = $iso_code;
        }

        return $results;
    }

    public function get_thumbs(array $requests): array {
        if (count($requests) > self::MAX_THUMB_REQUESTS) {
            return [];
        }

        $hrefs = [];

        foreach ($requests as $req) {
            if (
                !is_array($req)
                || !isset($req['type'], $req['href'], $req['width'], $req['height'])
                || !is_string($req['type'])
                || !is_string($req['href'])
                || !is_numeric($req['width'])
                || !is_numeric($req['height'])
            ) {
                $hrefs[] = null;
                continue;
            }
            $thumb = new Thumb($this);
            $hrefs[] = $thumb->thumb($req['type'], $req['href'], (int)$req['width'], (int)$req['height']);
        }

        return $hrefs;
    }

    private function prefix_x_head_href(string $href): string {
        if (preg_match('@^(https?://|/)@i', $href)) {
            return $href;
        }

        return $this->setup->get('PUBLIC_HREF') . 'ext/' . $href;
    }

    private function get_fonts_html(): string {
        $fonts = $this->query_option('view.fonts', []);
        $fonts_mono = $this->query_option('view.fontsMono', []);

        $html = '<style class="x-head">';

        if (count($fonts) > 0) {
            $html .= '#root,input,select{font-family:"' . implode('","', $fonts) . '"!important}';
        }

        if (count($fonts_mono) > 0) {
            $html .= 'pre,code{font-family:"' . implode('","', $fonts_mono) . '"!important}';
        }

        $html .= '</style>';

        return $html;
    }

    public function get_x_head_html(): string {
        $scripts = $this->query_option('resources.scripts', []);
        $styles = $this->query_option('resources.styles', []);

        $html = '';

        foreach ($styles as $href) {
            $html .= '<link rel="stylesheet" href="' . $this->prefix_x_head_href($href) . '" class="x-head">';
        }

        foreach ($scripts as $href) {
            $html .= '<script src="' . $this->prefix_x_head_href($href) . '" class="x-head"></script>';
        }

        $html .= $this->get_fonts_html();

        return $html;
    }
}
