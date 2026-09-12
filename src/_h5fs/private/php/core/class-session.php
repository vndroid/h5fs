<?php

class Session {
    private const KEY_PREFIX = '__H5FS__';
    private array $store;

    public function __construct(array &$store) {
        $this->store = &$store;
    }

    public function set(string $key, mixed $value): void {
        $this->store[self::KEY_PREFIX . $key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed {
        $key = self::KEY_PREFIX . $key;
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }
}
