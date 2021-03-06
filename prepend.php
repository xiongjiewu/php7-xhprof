<?php
date_default_timezone_set('PRC');
if (function_exists('php_sapi_name') && php_sapi_name() != 'cli' && extension_loaded('xhprof')) {

    if (!function_exists('__xhprof_getallheaders'))
    {
        function __xhprof_getallheaders()
        {
            $headers = [];
            foreach ($_SERVER as $name => $value)
            {
                if (substr($name, 0, 5) == 'HTTP_')
                {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
            return $headers;
        }
    }

    if (!function_exists('fastcgi_finish_request')) {
        function fastcgi_finish_request() {echo 0000000;};
    }

    if (!function_exists('__xhprof_url_is_hit')) {
        function __xhprof_url_is_hit($pattern, $value)
        {
	    return !(strpos($value, str_replace('@', '', $pattern)) === false);
        }
    }

    $data_file = __DIR__ . DIRECTORY_SEPARATOR . 'data.bin';
    if (file_exists($data_file) &&
        false !== ($data = file_get_contents($data_file)) &&
        false !== ($records = unserialize($data)) &&
        is_array($records)
    ) {
        $cfg = [];
        $request_url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        foreach ($records as $record) {
            if (!isset($record['url'])) {
                continue;
            }
            if (__xhprof_url_is_hit($record['url'], $request_url)) {
                $cfg = $record;
                break;
            }
        }

        if (isset($cfg['name']) && isset($cfg['frequency']) && isset($cfg['start_at']) && isset($cfg['end_at'])) {
            $now = time();
            if (strtotime($cfg['start_at']) < $now && $now < strtotime($cfg['end_at'])) {
                // random capture
                if ($cfg['frequency'] > 1) {
                    $cfg['frequency'] = 1;
                }
                if ($cfg['frequency'] < 0.01) {
                    $cfg['frequency'] = 0.01;
                }

                $frequency = $cfg['frequency'] * 100;
                if (mt_rand(0, 100) <= $frequency) {
                    $GLOBALS['xhprof_vars'] = [
                        'get'     => $_GET,
                        'post'    => $_POST,
                        'cookie'  => $_COOKIE,
                        'headers' => __xhprof_getallheaders(),
                        'raw'     => file_get_contents("php://input")
                    ];
                    xhprof_enable();
                    $app_name = $cfg['name'];
                    register_shutdown_function(function() use ($app_name) {
			if (function_exists('fastcgi_finish_request')) {
                            fastcgi_finish_request();
                        }

                        !defined('DS') && define('DS', DIRECTORY_SEPARATOR);
                        $inc_file = __DIR__ . DS . 'xhprof_lib'. DS . 'utils' . DS . 'xhprof_runs.php';
                        if (!file_exists($inc_file)) {
                            return;
                        }
                        require $inc_file;
                        $GLOBALS['xhprof_vars']['data'] = xhprof_disable();
                        $runs = new \XHProfRuns_Default();
                        $runs->save_run($GLOBALS['xhprof_vars'], $app_name);
                    });
                }
            }
        }
    }
}
