<?php

class Item {
    public static function cmp(Item $item1, Item $item2): int {
        if ($item1->is_folder && !$item2->is_folder) {
            return -1;
        }
        if (!$item1->is_folder && $item2->is_folder) {
            return 1;
        }

        return strcasecmp($item1->path, $item2->path);
    }

    public static function get(Context $context, string $path, &$cache): ?Item {
        if (!str_starts_with($path, $context->get_setup()->get('ROOT_PATH'))) {
            return null;
        }

        if (is_array($cache) && array_key_exists($path, $cache)) {
            return $cache[$path];
        }

        $item = new Item($context, $path);

        if (is_array($cache)) {
            $cache[$path] = $item;
        }
        return $item;
    }

    public string $path;
    public string $href;
    public $date;
    public $size;
    public bool $is_folder;
    public bool $is_content_fetched;

    private function __construct(public Context $context, string $path) {
        $this->path = Util::normalize_path($path, false);
        $this->is_folder = is_dir($this->path);
        $this->href = $context->to_href($this->path, $this->is_folder);
        $this->date = @filemtime($this->path);
        $this->size = Util::filesize($context, $this->path);
        $this->is_content_fetched = false;
    }

    public function to_json_object(): array {
        $obj = [
            'href' => $this->href,
            'time' => $this->date * 1000, // seconds (PHP) to milliseconds (JavaScript)
            'size' => $this->size
        ];

        if ($this->is_folder) {
            $obj['managed'] = $this->context->is_managed_href($this->href);
            $obj['fetched'] = $this->is_content_fetched;
        }

        return $obj;
    }

    public function get_parent(&$cache): ?Item {
        $parent_path = Util::normalize_path(dirname($this->path), false);
        if ($parent_path !== $this->path && str_starts_with($parent_path, $this->context->get_setup()->get('ROOT_PATH'))) {
            return Item::get($this->context, $parent_path, $cache);
        }
        return null;
    }

    public function get_content(&$cache): array {
        $items = [];

        if (!$this->context->is_managed_href($this->href)) {
            return $items;
        }

        $files = $this->context->read_dir($this->path);
        foreach ($files as $file) {
            $item = Item::get($this->context, $this->path . '/' . $file, $cache);
            $items[$item->path] = $item;
        }

        $this->is_content_fetched = true;

        return $items;
    }
}
