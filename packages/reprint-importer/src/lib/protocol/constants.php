<?php

/**
 * The wire-protocol version this importer speaks.
 *
 * Both the export plugin (server) and the importer (client) are deployed
 * independently.  These two constants let them detect incompatibility at
 * preflight time instead of producing silent corruption.
 *
 * Bump this whenever a change to the wire protocol (cursor encoding,
 * multipart structure, header names, endpoint parameters, response format)
 * would break an older export plugin.
 */
if (!defined('REPRINT_IMPORTER_PROTOCOL_VERSION')) {
    define('REPRINT_IMPORTER_PROTOCOL_VERSION', 1);
}

/**
 * The oldest *export plugin* protocol version this importer can talk to.
 *
 * During preflight-assert the importer checks that the remote's
 * protocol_version is >= this value; if not, it tells the user to
 * update the export plugin.
 *
 * Raise this when you drop backward-compatibility with old export plugins.
 * Keep it equal to REPRINT_IMPORTER_PROTOCOL_VERSION if no backward compat is needed.
 */
if (!defined('REPRINT_IMPORTER_MIN_EXPORT_VERSION')) {
    define('REPRINT_IMPORTER_MIN_EXPORT_VERSION', 1);
}
