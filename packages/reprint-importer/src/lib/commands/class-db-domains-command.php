<?php

namespace Reprint\Importer\Command;

use RuntimeException;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\Sql\SqlDomainScanner;
use Reprint\Importer\UrlRewrite\DomainCollector;

final class DbDomainsCommand extends ImportCommand
{
    public function execute(ImportRuntime $client, array $options): ?ImportCommandResult
    {
        $paths = $client->paths();
        $domains_file = $paths->domains_file();
        $sql_file = $paths->sql_file();

        if (file_exists($domains_file)) {
            $domains = json_decode(file_get_contents($domains_file), true);
            if (!is_array($domains)) {
                throw new RuntimeException("Failed to parse {$domains_file}");
            }

            return new DbDomainsResult($domains);
        }

        if (!file_exists($sql_file)) {
            throw new RuntimeException(
                "No domain data found. Run db-pull first, or place a db.sql file in {$paths->state_dir()}.",
            );
        }

        $query_stream = new WP_MySQL_Naive_Query_Stream();
        $domain_collector = new DomainCollector();
        $domain_scanner = new SqlDomainScanner($client->audit_logger());

        $sql_handle = fopen($sql_file, "r");
        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        try {
            $chunk_size = 64 * 1024;
            while (!feof($sql_handle)) {
                $data = fread($sql_handle, $chunk_size);
                if ($data === false || $data === "") {
                    break;
                }
                $query_stream->append_sql($data);
                $domain_scanner->drain_query_stream(
                    $query_stream,
                    $domain_collector,
                );
            }

            $query_stream->mark_input_complete();
            $domain_scanner->drain_query_stream(
                $query_stream,
                $domain_collector,
            );
        } finally {
            fclose($sql_handle);
        }

        $domains = $domain_collector->get_domains();

        file_put_contents(
            $domains_file,
            json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return new DbDomainsResult($domains);
    }
}
