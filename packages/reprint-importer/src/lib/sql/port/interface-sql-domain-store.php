<?php

namespace Reprint\Importer\Sql\Port;

interface SqlDomainStore
{
    /**
     * @return array<int, string>
     */
    public function load(): array;

    /**
     * @param array<int, string> $domains
     */
    public function persist(array $domains): void;
}
