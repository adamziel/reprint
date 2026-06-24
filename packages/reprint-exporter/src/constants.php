<?php
/**
 * Exporter wire-protocol and stat constants.
 */

if (!defined('REPRINT_EXPORTER_PROTOCOL_VERSION')) {
    /**
     * The wire-protocol version this export plugin speaks.
     *
     * Both the export plugin (server) and the importer (client) are deployed
     * independently. This lets them detect incompatibility at preflight time
     * instead of producing silent corruption.
     */
    define('REPRINT_EXPORTER_PROTOCOL_VERSION', 1);
}

if (!defined('REPRINT_EXPORTER_MIN_IMPORT_VERSION')) {
    /**
     * The oldest importer protocol version this export plugin can talk to.
     */
    define('REPRINT_EXPORTER_MIN_IMPORT_VERSION', 1);
}

if (!defined('REPRINT_EXPORTER_STAT_TYPE_MASK')) {
    // File type mask + file type values (top bits of st_mode).
    define('REPRINT_EXPORTER_STAT_TYPE_MASK',   0170000);
    define('REPRINT_EXPORTER_STAT_TYPE_SOCKET', 0140000);
    define('REPRINT_EXPORTER_STAT_TYPE_LINK',   0120000);
    define('REPRINT_EXPORTER_STAT_TYPE_FILE',   0100000);
    define('REPRINT_EXPORTER_STAT_TYPE_BLOCK',  0060000);
    define('REPRINT_EXPORTER_STAT_TYPE_DIR',    0040000);
    define('REPRINT_EXPORTER_STAT_TYPE_CHAR',   0020000);
    define('REPRINT_EXPORTER_STAT_TYPE_FIFO',   0010000);
}
