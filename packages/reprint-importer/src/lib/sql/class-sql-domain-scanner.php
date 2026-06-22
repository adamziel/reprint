<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Observability\NullAuditLogger;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\UrlRewrite\Base64ValueScanner;
use Reprint\Importer\UrlRewrite\DomainCollector;

final class SqlDomainScanner
{
    private AuditLogger $audit;

    public function __construct(?AuditLogger $audit = null)
    {
        $this->audit = $audit ?? new NullAuditLogger();
    }

    public function drain_query_stream(
        WP_MySQL_Naive_Query_Stream $query_stream,
        DomainCollector $domain_collector,
        int $statements_counted = 0
    ): int {
        while ($query_stream->next_query()) {
            $query = $query_stream->get_query();
            $statements_counted++;

            if (!SqlStatementInspector::starts_with_token($query, \WP_MySQL_Lexer::INSERT_SYMBOL)) {
                continue;
            }

            if (strpos($query, "FROM_BASE64(") === false) {
                continue;
            }

            $table = SqlStatementInspector::extract_insert_table($query);
            $is_options_table = substr($table, -8) === '_options';

            $scanner = new Base64ValueScanner($query);
            while ($scanner->next_value()) {
                $option_name = null;
                $match_offset = $scanner->get_match_offset();

                if ($is_options_table) {
                    $option_name = SqlStatementInspector::extract_option_name($query, $match_offset);
                    if ($this->is_transient_option($option_name)) {
                        continue;
                    }
                }

                $new_domains = $domain_collector->scan($scanner->get_value());
                if (empty($new_domains)) {
                    continue;
                }

                $this->audit_discovered_domains(
                    $new_domains,
                    $table,
                    SqlStatementInspector::extract_row_identifier($query, $match_offset),
                    $option_name,
                );
            }
        }

        return $statements_counted;
    }

    private function is_transient_option(?string $option_name): bool
    {
        if ($option_name === null) {
            return false;
        }

        return strpos($option_name, '_transient') === 0
            || strpos($option_name, '_site_transient') === 0;
    }

    /**
     * @param array<int, string> $domains
     */
    private function audit_discovered_domains(
        array $domains,
        string $table,
        string $row_id,
        ?string $option_name
    ): void {
        $option_context = $option_name !== null ? ' option=' . $option_name : '';

        foreach ($domains as $domain) {
            $this->audit->record(
                sprintf(
                    "NEW DOMAIN | %s | table=%s %s%s",
                    $domain,
                    $table,
                    $row_id,
                    $option_context,
                ),
                false,
            );
        }
    }
}
