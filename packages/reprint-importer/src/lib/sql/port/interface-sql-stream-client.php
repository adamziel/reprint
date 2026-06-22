<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Protocol\StreamingContext;

interface SqlStreamClient
{
    /**
     * @param array<string, mixed> $params
     */
    public function build_url(string $endpoint, ?string $cursor, array $params): string;

    /**
     * @return array<string, mixed>
     */
    public function tuned_params(string $endpoint): array;

    /**
     * @param array<string, mixed>|null $post_data
     */
    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void;

    /**
     * @param array<string, mixed> $response_stats
     */
    public function finalize_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void;
}
