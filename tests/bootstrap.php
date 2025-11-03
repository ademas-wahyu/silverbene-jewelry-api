<?php
require_once __DIR__ . '/wordpress-stubs.php';

// Ensure global containers exist.
$GLOBALS['__wp_filters'] = [];
$GLOBALS['__wp_options'] = [];
$GLOBALS['__wp_remote_request_callback'] = null;

require_once __DIR__ . '/../includes/class-silverbene-api-client.php';
