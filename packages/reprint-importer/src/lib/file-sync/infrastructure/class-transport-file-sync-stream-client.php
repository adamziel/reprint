<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Transport\ImportHttpSession;

final class TransportFileSyncStreamClient implements FileSyncStreamClient
{
    private ImportHttpSession $session;

    public function __construct(ImportHttpSession $session)
    {
        $this->session = $session;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function build_url(string $endpoint, ?string $cursor, array $params): string
    {
        return $this->session->build_url($endpoint, $cursor, $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function tuned_params(string $endpoint): array
    {
        return $this->session->tuned_params($endpoint);
    }

    /**
     * @param array<string, mixed>|null $post_data
     */
    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        $this->session->fetch_streaming($url, $cursor, $context, $post_data, $phase);
    }

    /**
     * @param array<string, mixed> $response_stats
     */
    public function finalize_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        $this->session->finalize_tuned_request($endpoint, $wall_time, $response_stats);
    }
}
