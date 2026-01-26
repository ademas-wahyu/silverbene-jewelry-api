<?php
// Minimal WordPress function shims for PHPUnit tests.

if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        global $__wp_filters;
        if (!isset($__wp_filters[$tag])) {
            $__wp_filters[$tag] = [];
        }
        if (!isset($__wp_filters[$tag][$priority])) {
            $__wp_filters[$tag][$priority] = [];
        }
        $__wp_filters[$tag][$priority][] = [
            'function' => $function_to_add,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        global $__wp_filters;
        if (empty($__wp_filters[$tag])) {
            return $value;
        }

        ksort($__wp_filters[$tag]);

        $args = func_get_args();
        foreach ($__wp_filters[$tag] as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                $accepted_args = $callback['accepted_args'];
                $call_args = array_slice($args, 1, $accepted_args);
                $value = $function(...$call_args);
                $args[1] = $value;
            }
        }

        return $value;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }
        if (!is_array($args)) {
            $args = [];
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string)
    {
        return rtrim($string, "\\/ ");
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url)
    {
        $parsed = parse_url($url);
        $query = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        if (is_array($args)) {
            $query = array_merge($query, $args);
        }

        $parsed['query'] = http_build_query($query);

        $scheme   = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host     = $parsed['host'] ?? '';
        $port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path     = $parsed['path'] ?? '';
        $querystr = $parsed['query'] !== '' ? '?' . $parsed['query'] : '';

        return $scheme . $host . $port . $path . $querystr;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = [])
    {
        global $__wp_remote_request_callback;
        if (is_callable($__wp_remote_request_callback)) {
            return call_user_func($__wp_remote_request_callback, $url, $args);
        }

        return [
            'response' => ['code' => 200],
            'body' => json_encode([]),
        ];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return isset($response['response']['code']) ? intval($response['response']['code']) : null;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return isset($response['body']) ? $response['body'] : null;
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        global $__wp_options;
        return $__wp_options[$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value)
    {
        global $__wp_options;
        $__wp_options[$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        global $__wp_options;
        unset($__wp_options[$name]);
        return true;
    }
}

if (!function_exists('wc_clean')) {
    function wc_clean($var)
    {
        if (is_array($var)) {
            return array_map('wc_clean', $var);
        }

        if (!is_scalar($var)) {
            return $var;
        }

        $value = strip_tags((string) $var);
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = trim($value);

        return $value;
    }
}
