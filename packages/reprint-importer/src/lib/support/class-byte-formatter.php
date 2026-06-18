<?php

namespace Reprint\Importer\Support;

final class ByteFormatter
{
    public static function format(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf("%.1f GB", $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf("%.1f MB", $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf("%.1f KB", $bytes / 1024);
        }
        return "{$bytes} B";
    }
}
