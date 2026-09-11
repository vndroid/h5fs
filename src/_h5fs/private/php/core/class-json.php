<?php

class Json {
    private const SINGLE = 1;
    private const MULTI = 2;

    public static function load(string $path): array {
        if (!is_readable($path)) {
            return [];
        }

        $json = file_get_contents($path);
        return Json::decode($json) ?? [];
    }

    public static function save(string $path, $obj): bool {
        $json = json_encode($obj);
        return file_put_contents($path, $json) !== false;
    }

    private static function decode(string $json): ?array {
        $decoded = json_decode(Json::strip($json), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function strip(string $commented_json): string {
        $insideString = false;
        $insideComment = false;
        $json = '';

        for ($i = 0, $len = strlen($commented_json); $i < $len; $i += 1) {
            $char = $commented_json[$i];
            $charchar = $char . ($i + 1 < $len ? $commented_json[$i + 1] : '');
            $prevChar = $i > 0 ? $commented_json[$i - 1] : '';

            if (!$insideComment && $char === '"' && $prevChar !== "\\") {
                $insideString = !$insideString;
            }

            if ($insideString) {
                $json .= $char;
            } elseif (!$insideComment && $charchar === '//') {
                $insideComment = Json::SINGLE;
                $i += 1;
            } elseif (!$insideComment && $charchar === '/*') {
                $insideComment = Json::MULTI;
                $i += 1;
            } elseif (!$insideComment) {
                $json .= $char;
            } elseif ($insideComment === Json::SINGLE && $charchar === "\r\n") {
                $insideComment = false;
                $json .= $charchar;
                $i += 1;
            } elseif ($insideComment === Json::SINGLE && $char === "\n") {
                $insideComment = false;
                $json .= $char;
            } elseif ($insideComment === Json::MULTI && $charchar === '*/') {
                $insideComment = false;
                $i += 1;
            }
        }

        return $json;
    }
}
