<?php

namespace Sitetus\WP\Rest;

use Sitetus\WP\Runner;
use Sitetus\WP\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/sitetus/v1/collect — the endpoint the panel calls. There is
 * no permission callback in the WordPress sense: authorization IS the
 * signature the core verifies (project key + Ed25519/RSA over method,
 * path, timestamp, nonce, body hash). Anything unsigned or invalid gets a
 * 404 with no body, as the single-file collector does.
 */
final class CollectEndpoint
{
    public const NAMESPACE = 'sitetus/v1';

    public const ROUTE = '/collect';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $headers = [];

        foreach ($request->get_headers() as $name => $values) {
            $value = is_array($values) ? (string) reset($values) : (string) $values;
            $headers[strtolower(str_replace('_', '-', (string) $name))] = $value;
        }

        // The signed path is the request path as the server saw it: the pretty
        // route or "/" for the ?rest_route= form (the panel signs the same thing).
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $body = (string) $request->get_body();

        // No PHP diagnostics may reach the response (core 0.5.1 does the same in handle()).
        $previousDisplay = @ini_get('display_errors');
        @ini_set('display_errors', '0');
        set_error_handler(static function (): bool {
            return true;
        });
        ob_start();

        try {
            $result = Runner::run('GET', $path === '' ? '/' : $path, $headers, $body, time());
        } finally {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            restore_error_handler();
            @ini_set('display_errors', (string) $previousDisplay);
        }

        if ($result['status'] === 200) {
            Settings::recordPull(200);
        } elseif ($result['status'] === 403) {
            Settings::recordPull(403);
        }

        $response = new WP_REST_Response($result['body'], $result['status']);

        foreach ($result['headers'] as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}
