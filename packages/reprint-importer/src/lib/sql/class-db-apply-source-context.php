<?php

namespace Reprint\Importer\Sql;

final class DbApplySourceContext
{
    /** @var array<string, mixed> */
    private array $preflight_data;
    private string $webhost;

    /**
     * @param array<string, mixed> $preflight_data
     */
    public function __construct(array $preflight_data, string $webhost)
    {
        $this->preflight_data = $preflight_data;
        $this->webhost = $webhost !== "" ? $webhost : "other";
    }

    /**
     * @return array<string, mixed>
     */
    public function preflight_data(): array
    {
        return $this->preflight_data;
    }

    public function webhost(): string
    {
        return $this->webhost;
    }

    public function table_prefix(): string
    {
        return $this->preflight_data["database"]["wp"]["table_prefix"] ?? 'wp_';
    }

    public function content_dir(): string
    {
        return rtrim(
            $this->preflight_data["database"]["wp"]["paths_urls"]["content_dir"] ?? "",
            "/",
        );
    }
}
