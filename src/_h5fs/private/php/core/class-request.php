<?php

readonly class Request {
    private array $params;

    public function __construct(array $params, string $body) {
        $data = json_decode($body, true);
        $this->params = is_array($data) ? $data : $params;
    }

    public function query(string $keypath = '', mixed $default = Util::NO_DEFAULT): mixed {
        $value = Util::array_query($this->params, $keypath, Util::NO_DEFAULT);

        if ($value === Util::NO_DEFAULT) {
            Util::json_fail(Util::ERR_MISSING_PARAM, 'parameter \'' . $keypath . '\' is missing', $default === Util::NO_DEFAULT);
            return $default;
        }

        return $value;
    }

    public function query_boolean(string $keypath = '', mixed $default = Util::NO_DEFAULT): bool {
        $value = $this->query($keypath, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function query_numeric(string $keypath = '', mixed $default = Util::NO_DEFAULT): int {
        $value = $this->query($keypath, $default);
        Util::json_fail(Util::ERR_ILLIGAL_PARAM, 'parameter \'' . $keypath . '\' is not numeric', !is_numeric($value));
        return (int)$value;
    }

    /**
     * Returns the parameter as string. Numbers are accepted and converted,
     * any other type (arrays, objects, booleans, null) is rejected with a
     * JSON error instead of causing a TypeError further down.
     */
    public function query_string(string $keypath = '', ?string $default = Util::NO_DEFAULT): ?string {
        $value = $this->query($keypath, $default);
        if ($value === null && $default === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        Util::json_fail(Util::ERR_ILLIGAL_PARAM, 'parameter \'' . $keypath . '\' is no string', !is_string($value));
        return $value;
    }

    public function query_array(string $keypath = '', mixed $default = Util::NO_DEFAULT): array {
        $value = $this->query($keypath, $default);
        Util::json_fail(Util::ERR_ILLIGAL_PARAM, 'parameter \'' . $keypath . '\' is no array', !is_array($value));
        return $value;
    }
}
