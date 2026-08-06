<?php

class Session {
    private const KEY_PREFIX = '__H5AI__';
    private array $store;

    public function __construct(array &$store) {
        $this->store = &$store;
    }

    public function set(string $key, $value): void {
        $this->store[self::KEY_PREFIX . $key] = $value;
    }

    public function get(string $key, $default = null) {
        $key = self::KEY_PREFIX . $key;
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }
}
