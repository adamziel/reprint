<?php

namespace Reprint\Importer\Session;

use Reprint\Importer\Observability\AuditLogger;

final class StatePathCodec
{
    private const PREFIX = "base64:";

    private ?AuditLogger $audit;

    public function __construct(?AuditLogger $audit = null)
    {
        $this->audit = $audit;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function encode_value($value)
    {
        if (!is_string($value) || $value === "") {
            return $value;
        }
        return self::PREFIX . base64_encode($value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function decode_value($value)
    {
        if (!is_string($value) || $value === "") {
            return $value;
        }
        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $encoded = substr($value, strlen(self::PREFIX));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            if ($this->audit !== null) {
                $this->audit->record(
                    "Warning: invalid base64-encoded state path; resetting field",
                    true,
                );
            }
            return null;
        }
        return $decoded;
    }

    public function encode_preflight_data_paths(array $data): array
    {
        return $this->map_preflight_path_fields($data, [$this, 'encode_value']);
    }

    public function decode_preflight_data_paths(array $data): array
    {
        return $this->map_preflight_path_fields($data, [$this, 'decode_value']);
    }

    private function map_preflight_path_fields(array $data, callable $map): array
    {
        if (isset($data["wp_detect"]["searched"]) && is_array($data["wp_detect"]["searched"])) {
            foreach ($data["wp_detect"]["searched"] as $idx => $path) {
                $data["wp_detect"]["searched"][$idx] = call_user_func($map, $path);
            }
        }

        if (isset($data["wp_detect"]["roots"]) && is_array($data["wp_detect"]["roots"])) {
            foreach ($data["wp_detect"]["roots"] as $idx => $root) {
                if (!is_array($root)) {
                    continue;
                }
                foreach (["path", "wp_load_path", "wp_config_path"] as $key) {
                    if (array_key_exists($key, $root)) {
                        $data["wp_detect"]["roots"][$idx][$key] = call_user_func($map, $root[$key]);
                    }
                }
            }
        }

        if (isset($data["runtime"]) && is_array($data["runtime"])) {
            foreach (["temp_dir", "document_root", "script_filename", "cwd"] as $key) {
                if (array_key_exists($key, $data["runtime"])) {
                    $data["runtime"][$key] = call_user_func($map, $data["runtime"][$key]);
                }
            }
        }

        if (isset($data["filesystem"]["directories"]) && is_array($data["filesystem"]["directories"])) {
            foreach ($data["filesystem"]["directories"] as $idx => $dir_entry) {
                if (!is_array($dir_entry) || !array_key_exists("path", $dir_entry)) {
                    continue;
                }
                $data["filesystem"]["directories"][$idx]["path"] = call_user_func($map, $dir_entry["path"]);
            }
        }

        if (isset($data["htaccess"]["files"]) && is_array($data["htaccess"]["files"])) {
            foreach ($data["htaccess"]["files"] as $idx => $file_entry) {
                if (!is_array($file_entry) || !array_key_exists("path", $file_entry)) {
                    continue;
                }
                $data["htaccess"]["files"][$idx]["path"] = call_user_func($map, $file_entry["path"]);
            }
        }

        if (isset($data["wp_content"]["roots"]) && is_array($data["wp_content"]["roots"])) {
            foreach ($data["wp_content"]["roots"] as $idx => $root_entry) {
                if (!is_array($root_entry)) {
                    continue;
                }
                foreach (["root", "content_dir"] as $key) {
                    if (array_key_exists($key, $root_entry)) {
                        $data["wp_content"]["roots"][$idx][$key] = call_user_func($map, $root_entry[$key]);
                    }
                }
            }
        }

        return $data;
    }

}
