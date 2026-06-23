<?php

namespace Reprint\Importer\Application\Result;

final class DbDomainsResult implements ImportCommandResult
{
    /** @var array<int, string> */
    private $domains;

    /**
     * @param array<int, mixed> $domains
     */
    public function __construct(array $domains)
    {
        $this->domains = [];
        foreach ($domains as $domain) {
            if (is_string($domain)) {
                $this->domains[] = $domain;
            }
        }
    }

    public function type(): string
    {
        return 'db-domains';
    }

    /**
     * @return array<int, string>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    public function to_array(): array
    {
        return [
            'type' => $this->type(),
            'domains' => $this->domains,
        ];
    }
}
