<?php

class Bootstrap {
    private const AUTOPATHS = ['core', 'ext'];

    public static function run(): void {
        spl_autoload_register([self::class, 'autoload']);
        putenv('LANG=en_US.UTF-8');
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        date_default_timezone_set(date_default_timezone_get());
        session_start();

        $session = new Session($_SESSION);
        $request = new Request($_REQUEST, file_get_contents('php://input'));
        $setup = new Setup($request->query_boolean('refresh', false));
        $context = new Context($session, $request, $setup);

        if ($context->is_api_request()) {
            self::handle_api_errors();
            (new Api($context))->apply();
        } elseif ($context->is_info_request()) {
            $public_href = $setup->get('PUBLIC_HREF');
            $x_head_tags = $context->get_x_head_html();
            $fallback_mode = false;
            require __DIR__ . '/pages/info.php';
        } else {
            $public_href = $setup->get('PUBLIC_HREF');
            $x_head_tags = $context->get_x_head_html();
            $fallback_mode = $context->is_fallback_mode();
            $fallback_html = (new Fallback($context))->get_html();
            require __DIR__ . '/pages/index.php';
        }
    }

    /**
     * API responses are JSON: never mix PHP error output into them and turn
     * any uncaught error into a generic JSON error, the details only go to
     * the server's error log.
     */
    private static function handle_api_errors(): void {
        ini_set('display_errors', '0');
        set_exception_handler(static function (\Throwable $err): void {
            error_log('h5fs: uncaught ' . get_class($err) . ': ' . $err->getMessage()
                . ' in ' . $err->getFile() . ':' . $err->getLine());
            // if output was already sent (e.g. a download), just stop
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-type: application/json;charset=utf-8');
                echo json_encode(['err' => Util::ERR_FAILED, 'msg' => 'internal error']);
            }
        });
    }

    public static function autoload(string $class_name): void {
        $filename = 'class-' . strtolower($class_name) . '.php';

        foreach (self::AUTOPATHS as $path) {
            $file = __DIR__ . '/' . $path . '/' . $filename;
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
}
