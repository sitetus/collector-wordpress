<?php

namespace Sitetus\WP\Rest;

if (! defined('ABSPATH')) {
    exit;
}

use Sitetus\WP\Runner;
use Sitetus\WP\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/sitetus/v1/collect — the endpoint the panel calls. There is
 * no permission callback in the WordPress sense: authorization IS the
 * signature the core verifies (project key + Ed25519/RSA over method,
 * path, timestamp, nonce, body hash). Anything unsigned, or signed for
 * another project key, gets a 404 with no body, as the single-file
 * collector does. A refusal the core explains (403) is passed on as it is:
 * its status and its fixed X-Sitetus-* headers, no body.
 */
final class CollectEndpoint
{
    /**
     * The refusals that follow a verified signature. Only these are recorded
     * as the last signed request: a 403 for a signature that did not verify
     * (core 0.7.4) comes from a caller that is not proven to be the panel,
     * and such a caller must not be able to make the site write.
     *
     * @var array<int, string>
     */
    private const VERIFIED_REFUSALS = ['skew', 'nonce', 'nonce-store'];

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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The path is part of the signed message and is only compared; a sanitizer that rewrote it would break the signature check.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $body = (string) $request->get_body();

        // No PHP diagnostics may reach the response: a warning printed into the body
        // would break the JSON the panel reads and could name a path on this server.
        // Both settings are put back as they were once the response is built.
        $previousDisplay = @ini_get('display_errors');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors -- For this one response only, restored in the finally block below.
        @ini_set('display_errors', '0');
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Not debug code: keeps warnings out of the response, restored in the finally block below.
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
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors -- Puts back the value read before the request was handled.
            @ini_set('display_errors', (string) $previousDisplay);
        }

        if ($result['status'] === 200) {
            Settings::recordPull(200);
        } elseif ($result['status'] === 403 && in_array($result['headers']['X-Sitetus-Error'] ?? '', self::VERIFIED_REFUSALS, true)) {
            Settings::recordPull(403);
        }

        $response = new WP_REST_Response($result['body'], $result['status']);

        foreach ($result['headers'] as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}
