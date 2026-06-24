<?php

namespace Reprint\Exporter;

/**
 * Builds the config array from HTTP GET/POST parameters and optional JSON body.
 *
 * @return array<string, mixed>
 */
function parse_http_config(): array
{
    $body = file_get_contents('php://input');
    if ($body === false) {
        $body = '';
    }

    $server = new Site_Export_HTTP_Server();
    return $server->parse_http_config($_GET, $_POST, $_SERVER, $body);
}
