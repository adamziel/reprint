<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Sql\Port\SqlDomainStore;

final class JsonSqlDomainStore implements SqlDomainStore
{
    private string $domains_file;

    public function __construct(string $domains_file)
    {
        $this->domains_file = $domains_file;
    }

    /**
     * @return array<int, string>
     */
    public function load(): array
    {
        if (!file_exists($this->domains_file)) {
            return [];
        }

        $domains = json_decode(file_get_contents($this->domains_file), true);
        return is_array($domains) ? $domains : [];
    }

    /**
     * @param array<int, string> $domains
     */
    public function persist(array $domains): void
    {
        file_put_contents(
            $this->domains_file,
            json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
