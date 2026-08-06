<?php

class Request {
    private array $params;

    public function __construct(array $params, string $body) {
        $data = json_decode($body, true);
        $this->params = is_array($data) ? $data : $params;
    }

    public function query(string $keypath = '', $default = Util::NO_DEFAULT) {
        $value = Util::array_query($this->params, $keypath, Util::NO_DEFAULT);

        if ($value === Util::NO_DEFAULT) {
            Util::json_fail(Util::ERR_MISSING_PARAM, 'parameter \'' . $keypath . '\' is missing', $default === Util::NO_DEFAULT);
            return $default;
        }

        return $value;
    }

    public function query_boolean(string $keypath = '', $default = Util::NO_DEFAULT): bool {
        $value = $this->query($keypath, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function query_numeric(string $keypath = '', $default = Util::NO_DEFAULT): int {
        $value = $this->query($keypath, $default);
        Util::json_fail(Util::ERR_ILLIGAL_PARAM, 'parameter \'' . $keypath . '\' is not numeric', !is_numeric($value));
        return (int)$value;
    }

    public function query_array(string $keypath = '', $default = Util::NO_DEFAULT): array {
        $value = $this->query($keypath, $default);
        Util::json_fail(Util::ERR_ILLIGAL_PARAM, 'parameter \'' . $keypath . '\' is no array', !is_array($value));
        return $value;
    }
}
