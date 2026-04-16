#!/usr/bin/env php
<?php
/**
 * binlog-json.php -- MySQL Binary Log to JSON Replication Client
 *
 * Connects to a MySQL server as a replica via the replication protocol,
 * receives the raw binlog event stream, and prints one JSON line per
 * event describing what changed and how.
 *
 * Usage:
 *   php binlog-json.php \
 *       --host=127.0.0.1 --port=3306 \
 *       --user=root --password=secret \
 *       [--binlog-file=mysql-bin.000001] [--binlog-pos=4] \
 *       [--server-id=999]
 *
 * If --binlog-file is omitted the program queries SHOW MASTER STATUS
 * and starts from the current position.
 */

// ================================================================
// Constants
// ================================================================

// Capability flags (only the ones we need)
const CAP_LONG_PASSWORD     = 0x00000001;
const CAP_FOUND_ROWS        = 0x00000002;
const CAP_LONG_FLAG         = 0x00000004;
const CAP_CONNECT_WITH_DB   = 0x00000008;
const CAP_PROTOCOL_41       = 0x00000200;
const CAP_TRANSACTIONS      = 0x00002000;
const CAP_SECURE_CONNECTION = 0x00008000;
const CAP_PLUGIN_AUTH       = 0x00080000;

// Binlog event types
const UNKNOWN_EVENT           = 0;
const QUERY_EVENT             = 2;
const STOP_EVENT              = 3;
const ROTATE_EVENT            = 4;
const FORMAT_DESCRIPTION_EVENT = 15;
const XID_EVENT               = 16;
const TABLE_MAP_EVENT         = 19;
const WRITE_ROWS_EVENT_V1     = 23;
const UPDATE_ROWS_EVENT_V1    = 24;
const DELETE_ROWS_EVENT_V1    = 25;
const HEARTBEAT_LOG_EVENT     = 27;
const WRITE_ROWS_EVENT_V2     = 30;
const UPDATE_ROWS_EVENT_V2    = 31;
const DELETE_ROWS_EVENT_V2    = 32;
const GTID_LOG_EVENT          = 33;
const PREVIOUS_GTIDS_LOG_EVENT = 35;

const EVENT_NAMES = [
    QUERY_EVENT              => 'QUERY',
    STOP_EVENT               => 'STOP',
    ROTATE_EVENT             => 'ROTATE',
    FORMAT_DESCRIPTION_EVENT => 'FORMAT_DESCRIPTION',
    XID_EVENT                => 'XID',
    TABLE_MAP_EVENT          => 'TABLE_MAP',
    WRITE_ROWS_EVENT_V1      => 'WRITE_ROWS_V1',
    UPDATE_ROWS_EVENT_V1     => 'UPDATE_ROWS_V1',
    DELETE_ROWS_EVENT_V1     => 'DELETE_ROWS_V1',
    HEARTBEAT_LOG_EVENT      => 'HEARTBEAT',
    WRITE_ROWS_EVENT_V2      => 'WRITE_ROWS_V2',
    UPDATE_ROWS_EVENT_V2     => 'UPDATE_ROWS_V2',
    DELETE_ROWS_EVENT_V2     => 'DELETE_ROWS_V2',
    GTID_LOG_EVENT           => 'GTID',
    PREVIOUS_GTIDS_LOG_EVENT => 'PREVIOUS_GTIDS',
];

// MySQL column types
const MYSQL_TYPE_DECIMAL     = 0;
const MYSQL_TYPE_TINY        = 1;
const MYSQL_TYPE_SHORT       = 2;
const MYSQL_TYPE_LONG        = 3;
const MYSQL_TYPE_FLOAT       = 4;
const MYSQL_TYPE_DOUBLE      = 5;
const MYSQL_TYPE_NULL        = 6;
const MYSQL_TYPE_TIMESTAMP   = 7;
const MYSQL_TYPE_LONGLONG    = 8;
const MYSQL_TYPE_INT24       = 9;
const MYSQL_TYPE_DATE        = 10;
const MYSQL_TYPE_TIME        = 11;
const MYSQL_TYPE_DATETIME    = 12;
const MYSQL_TYPE_YEAR        = 13;
const MYSQL_TYPE_NEWDATE     = 14;
const MYSQL_TYPE_VARCHAR     = 15;
const MYSQL_TYPE_BIT         = 16;
const MYSQL_TYPE_TIMESTAMP2  = 17;
const MYSQL_TYPE_DATETIME2   = 18;
const MYSQL_TYPE_TIME2       = 19;
const MYSQL_TYPE_JSON        = 245;
const MYSQL_TYPE_NEWDECIMAL  = 246;
const MYSQL_TYPE_ENUM        = 247;
const MYSQL_TYPE_SET         = 248;
const MYSQL_TYPE_TINY_BLOB   = 249;
const MYSQL_TYPE_MEDIUM_BLOB = 250;
const MYSQL_TYPE_LONG_BLOB   = 251;
const MYSQL_TYPE_BLOB        = 252;
const MYSQL_TYPE_VAR_STRING  = 253;
const MYSQL_TYPE_STRING      = 254;
const MYSQL_TYPE_GEOMETRY    = 255;

// Optional TABLE_MAP metadata types
const OPT_META_SIGNEDNESS    = 1;
const OPT_META_COLUMN_NAME   = 4;

// ================================================================
// Binary read/write helpers
// ================================================================

function read_u8(string $d, int &$o): int {
    $v = ord($d[$o]);
    $o++;
    return $v;
}

function read_u16(string $d, int &$o): int {
    $v = unpack('v', $d, $o)[1];
    $o += 2;
    return $v;
}

function read_u24(string $d, int &$o): int {
    $v = ord($d[$o]) | (ord($d[$o + 1]) << 8) | (ord($d[$o + 2]) << 16);
    $o += 3;
    return $v;
}

function read_u32(string $d, int &$o): int {
    $v = unpack('V', $d, $o)[1];
    $o += 4;
    // PHP unpack('V') returns signed on 32-bit, but on 64-bit it's fine
    return $v & 0xFFFFFFFF;
}

function read_u48(string $d, int &$o): int {
    $lo = read_u32($d, $o);
    $hi = read_u16($d, $o);
    return $lo | ($hi << 32);
}

function read_u64(string $d, int &$o): int {
    $lo = read_u32($d, $o);
    $hi = read_u32($d, $o);
    return $lo | ($hi << 32);
}

function read_u16_be(string $d, int &$o): int {
    $v = (ord($d[$o]) << 8) | ord($d[$o + 1]);
    $o += 2;
    return $v;
}

function read_u24_be(string $d, int &$o): int {
    $v = (ord($d[$o]) << 16) | (ord($d[$o + 1]) << 8) | ord($d[$o + 2]);
    $o += 3;
    return $v;
}

function read_u32_be(string $d, int &$o): int {
    $v = unpack('N', $d, $o)[1];
    $o += 4;
    return $v & 0xFFFFFFFF;
}

function read_u40_be(string $d, int &$o): int {
    $hi = ord($d[$o]);
    $o++;
    $lo = read_u32_be($d, $o);
    return ($hi << 32) | $lo;
}

function read_bytes(string $d, int &$o, int $n): string {
    $v = substr($d, $o, $n);
    $o += $n;
    return $v;
}

function read_nul_string(string $d, int &$o): string {
    $end = strpos($d, "\0", $o);
    if ($end === false) {
        $s = substr($d, $o);
        $o = strlen($d);
        return $s;
    }
    $s = substr($d, $o, $end - $o);
    $o = $end + 1;
    return $s;
}

/** Read a MySQL length-encoded integer. */
function read_lenenc(string $d, int &$o): ?int {
    $first = ord($d[$o]);
    $o++;
    if ($first < 0xFB) return $first;
    if ($first === 0xFB) return null; // NULL marker in result sets
    if ($first === 0xFC) { $v = unpack('v', $d, $o)[1]; $o += 2; return $v; }
    if ($first === 0xFD) { $v = read_u24($d, $o); return $v; }
    // 0xFE
    $v = read_u64($d, $o);
    return $v;
}

function read_lenenc_string(string $d, int &$o): ?string {
    $len = read_lenenc($d, $o);
    if ($len === null) return null;
    return read_bytes($d, $o, $len);
}

function write_u8(int $v): string { return chr($v & 0xFF); }
function write_u16(int $v): string { return pack('v', $v); }
function write_u24(int $v): string { return chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF); }
function write_u32(int $v): string { return pack('V', $v); }

// ================================================================
// MysqlBinlogClient
// ================================================================

class MysqlBinlogClient {
    private $socket;
    private int $seqNum = 0;
    private bool $checksumEnabled = false;
    /** @var array<int, array> table_id => table map info */
    private array $tableMaps = [];

    // --- Packet I/O ---

    public function connect(string $host, int $port): void {
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            throw new RuntimeException("TCP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($this->socket, 86400); // long timeout for replication
    }

    /** Read one MySQL packet, handling multi-packet reassembly. */
    public function readPacket(): string {
        $header = $this->readExact(4);
        $len = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $this->seqNum = ord($header[3]) + 1;
        $payload = $len > 0 ? $this->readExact($len) : '';

        // Multi-packet reassembly: if payload is exactly 0xFFFFFF bytes,
        // the next packet is a continuation.
        while ($len === 0xFFFFFF) {
            $header = $this->readExact(4);
            $len = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $this->seqNum = ord($header[3]) + 1;
            if ($len > 0) {
                $payload .= $this->readExact($len);
            }
        }
        return $payload;
    }

    public function writePacket(string $data): void {
        $len = strlen($data);
        $header = write_u24($len) . write_u8($this->seqNum);
        $this->seqNum++;
        fwrite($this->socket, $header . $data);
        fflush($this->socket);
    }

    private function readExact(int $n): string {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->socket, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException("Connection closed while reading (needed $n bytes, got " . strlen($buf) . ")");
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    // --- Authentication ---

    /**
     * Perform the MySQL handshake and authenticate.
     * Supports mysql_native_password (the most common auth plugin).
     */
    public function authenticate(string $user, string $password, string $database = ''): void {
        // Step 1: Read server greeting (HandshakeV10)
        $greeting = $this->readPacket();
        $o = 0;
        $protocolVersion = read_u8($greeting, $o);
        if ($protocolVersion !== 10) {
            throw new RuntimeException("Unsupported protocol version: $protocolVersion");
        }
        $serverVersion = read_nul_string($greeting, $o);
        $connectionId = read_u32($greeting, $o);
        $authData1 = read_bytes($greeting, $o, 8);
        $o++; // skip filler
        $capLow = read_u16($greeting, $o);
        $charset = read_u8($greeting, $o);
        $statusFlags = read_u16($greeting, $o);
        $capHigh = read_u16($greeting, $o);
        $serverCaps = $capLow | ($capHigh << 16);
        $authDataLen = 0;
        if ($serverCaps & CAP_PLUGIN_AUTH) {
            $authDataLen = read_u8($greeting, $o);
        } else {
            $o++; // skip
        }
        $o += 10; // reserved
        $authData2 = '';
        if ($serverCaps & CAP_SECURE_CONNECTION) {
            $part2Len = max(13, $authDataLen - 8);
            $authData2 = substr($greeting, $o, $part2Len);
            $o += $part2Len;
            // Strip trailing null if present
            $authData2 = rtrim($authData2, "\0");
        }
        $authPluginName = '';
        if ($serverCaps & CAP_PLUGIN_AUTH) {
            $authPluginName = read_nul_string($greeting, $o);
        }
        $scramble = $authData1 . $authData2;

        fwrite(STDERR, "Connected to $serverVersion (connection $connectionId)\n");

        // Step 2: Build and send HandshakeResponse41
        $clientCaps = CAP_LONG_PASSWORD | CAP_PROTOCOL_41 | CAP_SECURE_CONNECTION
                    | CAP_TRANSACTIONS | CAP_LONG_FLAG | CAP_PLUGIN_AUTH;
        if ($database !== '') {
            $clientCaps |= CAP_CONNECT_WITH_DB;
        }

        // Compute auth response for the plugin the server requests
        $authResponse = $this->computeAuthResponse($authPluginName, $password, $scramble);

        $packet = write_u32($clientCaps);
        $packet .= write_u32(0x01000000); // max packet size 16MB
        $packet .= write_u8(0x21);        // utf8 charset
        $packet .= str_repeat("\0", 23);   // reserved
        $packet .= $user . "\0";
        // Length-prefixed auth response
        $packet .= write_u8(strlen($authResponse)) . $authResponse;
        if ($database !== '') {
            $packet .= $database . "\0";
        }
        $packet .= $authPluginName . "\0";

        $this->seqNum = 1;
        $this->writePacket($packet);

        // Step 3: Handle auth response (may involve multiple round-trips)
        $this->handleAuthResponse($authPluginName, $password, $scramble);
        fwrite(STDERR, "Authenticated as '$user'\n");
    }

    /** Compute the initial auth response for the given plugin. */
    private function computeAuthResponse(string $plugin, string $password, string $scramble): string {
        if ($password === '') return '';
        if ($plugin === 'caching_sha2_password') {
            return $this->cachingSha2Auth($password, $scramble);
        }
        // Default: mysql_native_password
        return $this->nativePasswordAuth($password, $scramble);
    }

    /** mysql_native_password: SHA1(password) XOR SHA1(scramble + SHA1(SHA1(password))) */
    private function nativePasswordAuth(string $password, string $scramble): string {
        if ($password === '') return '';
        $hash1 = sha1($password, true);
        $hash2 = sha1($hash1, true);
        $hash3 = sha1($scramble . $hash2, true);
        return $hash1 ^ $hash3;
    }

    /** caching_sha2_password: SHA256(password) XOR SHA256(SHA256(SHA256(password)) + scramble) */
    private function cachingSha2Auth(string $password, string $scramble): string {
        if ($password === '') return '';
        $hash1 = hash('sha256', $password, true);
        $hash2 = hash('sha256', $hash1, true);
        $hash3 = hash('sha256', $hash2 . $scramble, true);
        return $hash1 ^ $hash3;
    }

    /**
     * Handle the server's auth response. This may involve multiple
     * round-trips for AuthSwitchRequest or caching_sha2_password
     * full-auth flow (RSA key exchange).
     */
    private function handleAuthResponse(string $plugin, string $password, string $scramble): void {
        $resp = $this->readPacket();
        $marker = ord($resp[0]);

        // OK — auth complete
        if ($marker === 0x00) return;

        // ERR
        if ($marker === 0xFF) {
            $o = 1;
            $errCode = read_u16($resp, $o);
            if (isset($resp[$o]) && $resp[$o] === '#') $o += 6; // skip sqlstate
            throw new RuntimeException("Auth failed [$errCode]: " . substr($resp, $o));
        }

        // AuthMoreData (0x01) — used by caching_sha2_password
        if ($marker === 0x01) {
            $status = ord($resp[1]);
            if ($status === 0x03) {
                // Fast auth success — server had the hash cached.
                // Next packet is OK.
                $ok = $this->readPacket();
                if (ord($ok[0]) !== 0x00) {
                    throw new RuntimeException("Expected OK after fast auth, got 0x" . dechex(ord($ok[0])));
                }
                return;
            }
            if ($status === 0x04) {
                // Full auth required — request server's RSA public key,
                // encrypt the password, and send it.
                $this->cachingSha2FullAuth($password, $scramble);
                return;
            }
            throw new RuntimeException("Unknown AuthMoreData status: 0x" . dechex($status));
        }

        // AuthSwitchRequest (0xFE)
        if ($marker === 0xFE) {
            $o = 1;
            $newPlugin = read_nul_string($resp, $o);
            $newScramble = substr($resp, $o, 20);
            $authResponse = $this->computeAuthResponse($newPlugin, $password, $newScramble);
            $this->writePacket($authResponse);
            // Recurse to handle the next response (could be OK, ERR, or more auth data)
            $this->handleAuthResponse($newPlugin, $password, $newScramble);
            return;
        }

        throw new RuntimeException("Unexpected auth response marker: 0x" . dechex($marker));
    }

    /**
     * caching_sha2_password full authentication over plaintext connection.
     * Requests the server's RSA public key, encrypts the password with it.
     */
    private function cachingSha2FullAuth(string $password, string $scramble): void {
        // Request server's RSA public key
        $this->writePacket("\x02");
        $keyResp = $this->readPacket();
        if (ord($keyResp[0]) !== 0x01) {
            throw new RuntimeException("Expected public key response, got 0x" . dechex(ord($keyResp[0])));
        }
        $publicKeyPem = substr($keyResp, 1);

        // XOR password+NUL with the scramble (repeating scramble as needed)
        $passwordNul = $password . "\0";
        $xored = '';
        $scrambleLen = strlen($scramble);
        for ($i = 0; $i < strlen($passwordNul); $i++) {
            $xored .= chr(ord($passwordNul[$i]) ^ ord($scramble[$i % $scrambleLen]));
        }

        // Encrypt with RSA OAEP
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new RuntimeException("Failed to parse server RSA public key");
        }
        $encrypted = '';
        if (!openssl_public_encrypt($xored, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException("RSA encryption failed: " . openssl_error_string());
        }

        $this->writePacket($encrypted);
        $ok = $this->readPacket();
        if (ord($ok[0]) !== 0x00) {
            $o = 1;
            if (ord($ok[0]) === 0xFF) {
                $code = read_u16($ok, $o);
                if (isset($ok[$o]) && $ok[$o] === '#') $o += 6;
                throw new RuntimeException("Auth failed after RSA [$code]: " . substr($ok, $o));
            }
            throw new RuntimeException("Expected OK after RSA auth, got 0x" . dechex(ord($ok[0])));
        }
    }

    // --- Simple SQL query (for setup commands) ---

    /**
     * Run a COM_QUERY and return result rows as arrays.
     * For queries that don't return rows (SET, etc.), returns [].
     */
    public function query(string $sql): array {
        $this->seqNum = 0;
        $this->writePacket(write_u8(0x03) . $sql);
        $resp = $this->readPacket();
        $marker = ord($resp[0]);

        // OK packet (no result set)
        if ($marker === 0x00) return [];
        // ERR packet
        if ($marker === 0xFF) {
            $o = 1;
            $code = read_u16($resp, $o);
            throw new RuntimeException("Query error [$code]: " . substr($resp, $o));
        }

        // Result set: first packet is column count
        $o = 0;
        $colCount = read_lenenc($resp, $o);

        // Column definitions
        $colNames = [];
        for ($i = 0; $i < $colCount; $i++) {
            $colDef = $this->readPacket();
            $co = 0;
            read_lenenc_string($colDef, $co); // catalog
            read_lenenc_string($colDef, $co); // schema
            read_lenenc_string($colDef, $co); // table (virtual)
            read_lenenc_string($colDef, $co); // table (physical)
            $colNames[] = read_lenenc_string($colDef, $co); // name
        }

        // EOF after columns
        $eof = $this->readPacket();

        // Rows
        $rows = [];
        while (true) {
            $rowPkt = $this->readPacket();
            if (ord($rowPkt[0]) === 0xFE && strlen($rowPkt) < 9) break; // EOF
            $ro = 0;
            $row = [];
            for ($i = 0; $i < $colCount; $i++) {
                $row[$colNames[$i]] = read_lenenc_string($rowPkt, $ro);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // --- Binlog dump setup ---

    /**
     * Configure replication settings, register as a replica, and begin
     * receiving the binlog event stream.
     */
    public function startBinlogDump(int $serverId, string $binlogFile, int $binlogPos): void {
        // Tell the server we understand checksums
        $this->query("SET @master_binlog_checksum = @@global.binlog_checksum");

        // If no binlog file given, discover current position
        if ($binlogFile === '') {
            $rows = $this->query("SHOW MASTER STATUS");
            if (empty($rows)) {
                throw new RuntimeException("SHOW MASTER STATUS returned no rows. Is binary logging enabled?");
            }
            $binlogFile = $rows[0]['File'];
            $binlogPos = (int)$rows[0]['Position'];
        }
        fwrite(STDERR, "Starting binlog dump from $binlogFile:$binlogPos\n");

        // COM_REGISTER_SLAVE (0x15)
        $this->seqNum = 0;
        $regPkt = write_u8(0x15);      // COM_REGISTER_SLAVE
        $regPkt .= write_u32($serverId);
        $regPkt .= write_u8(0);        // hostname length
        $regPkt .= write_u8(0);        // user length
        $regPkt .= write_u8(0);        // password length
        $regPkt .= write_u16(0);       // port
        $regPkt .= write_u32(0);       // replication rank (ignored)
        $regPkt .= write_u32(0);       // master id
        $this->writePacket($regPkt);
        $resp = $this->readPacket();
        if (ord($resp[0]) !== 0x00) {
            throw new RuntimeException("COM_REGISTER_SLAVE failed");
        }

        // COM_BINLOG_DUMP (0x12)
        $this->seqNum = 0;
        $dumpPkt = write_u8(0x12);          // COM_BINLOG_DUMP
        $dumpPkt .= write_u32($binlogPos);  // binlog position
        $dumpPkt .= write_u16(0);           // flags
        $dumpPkt .= write_u32($serverId);   // server id
        $dumpPkt .= $binlogFile;            // binlog filename
        $this->writePacket($dumpPkt);
        fwrite(STDERR, "Binlog dump started. Waiting for events...\n\n");
    }

    // --- Main event loop ---

    /**
     * Read binlog event packets forever, parse them, and yield JSON objects.
     * Each event is printed as one JSON line to stdout.
     */
    public function eventLoop(): void {
        while (true) {
            $packet = $this->readPacket();
            $marker = ord($packet[0]);

            if ($marker === 0xFF) {
                $o = 1;
                $code = read_u16($packet, $o);
                fwrite(STDERR, "Server error [$code]: " . substr($packet, $o) . "\n");
                break;
            }
            if ($marker === 0xFE && strlen($packet) < 9) {
                fwrite(STDERR, "End of binlog stream (EOF)\n");
                break;
            }

            // Skip the 0x00 OK marker; remainder is the raw binlog event
            $eventData = substr($packet, 1);
            $event = $this->parseEvent($eventData);
            if ($event !== null) {
                echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
        }
    }

    // --- Event parsing ---

    private function parseEvent(string $data): ?array {
        // Common event header: 19 bytes
        // timestamp(4) + type(1) + server_id(4) + event_length(4) + next_pos(4) + flags(2)
        if (strlen($data) < 19) return null;
        $o = 0;
        $timestamp = read_u32($data, $o);
        $type = read_u8($data, $o);
        $serverId = read_u32($data, $o);
        $eventLen = read_u32($data, $o);
        $nextPos = read_u32($data, $o);
        $flags = read_u16($data, $o);

        // Body starts at offset 19, ends before checksum (if enabled)
        $bodyEnd = strlen($data);
        if ($this->checksumEnabled && $type !== FORMAT_DESCRIPTION_EVENT) {
            $bodyEnd -= 4; // strip CRC32
        }
        $body = substr($data, 19, $bodyEnd - 19);

        $ts = date('Y-m-d\TH:i:sP', $timestamp);

        switch ($type) {
            case FORMAT_DESCRIPTION_EVENT:
                return $this->parseFormatDescription($body, $ts);
            case TABLE_MAP_EVENT:
                return $this->parseTableMap($body, $ts);
            case WRITE_ROWS_EVENT_V1:
            case WRITE_ROWS_EVENT_V2:
                return $this->parseRowsEvent($body, $ts, 'INSERT', $type >= 30);
            case UPDATE_ROWS_EVENT_V1:
            case UPDATE_ROWS_EVENT_V2:
                return $this->parseRowsEvent($body, $ts, 'UPDATE', $type >= 30);
            case DELETE_ROWS_EVENT_V1:
            case DELETE_ROWS_EVENT_V2:
                return $this->parseRowsEvent($body, $ts, 'DELETE', $type >= 30);
            case QUERY_EVENT:
                return $this->parseQueryEvent($body, $ts);
            case XID_EVENT:
                return $this->parseXidEvent($body, $ts);
            case ROTATE_EVENT:
                return $this->parseRotateEvent($body, $ts);
            case GTID_LOG_EVENT:
                return $this->parseGtidEvent($body, $ts);
            case HEARTBEAT_LOG_EVENT:
                return null; // silently skip
            case STOP_EVENT:
                return ['event' => 'stop', 'timestamp' => $ts];
            case PREVIOUS_GTIDS_LOG_EVENT:
                return null; // informational, skip
            default:
                $name = EVENT_NAMES[$type] ?? "UNKNOWN($type)";
                return ['event' => 'unknown', 'type_id' => $type, 'type_name' => $name, 'timestamp' => $ts];
        }
    }

    private function parseFormatDescription(string $body, string $ts): array {
        $o = 0;
        $binlogVersion = read_u16($body, $o);
        $serverVersion = rtrim(read_bytes($body, $o, 50), "\0");
        $createTimestamp = read_u32($body, $o);
        $headerLen = read_u8($body, $o);

        // Detect checksum algorithm: it's at the very end of the FDE body.
        // The last 5 bytes of the complete FDE (before any payload padding)
        // are: 1 byte checksum_alg + 4 bytes CRC32.
        // The checksum_alg is also encoded in the post-header-lengths array
        // at the very end, but the simplest way to read it is from the
        // raw event. The alg byte is at offset (total_len - 5) from body start.
        $totalBodyLen = strlen($body);
        if ($totalBodyLen >= 5) {
            $algByte = ord($body[$totalBodyLen - 5]);
            $this->checksumEnabled = ($algByte === 1); // 1 = CRC32
        }

        fwrite(STDERR, "Binlog format v$binlogVersion, server $serverVersion, checksum=" .
            ($this->checksumEnabled ? 'CRC32' : 'OFF') . "\n");

        return [
            'event' => 'format_description',
            'timestamp' => $ts,
            'binlog_version' => $binlogVersion,
            'server_version' => $serverVersion,
            'checksum' => $this->checksumEnabled ? 'CRC32' : 'NONE',
        ];
    }

    private function parseTableMap(string $body, string $ts): ?array {
        $o = 0;
        $tableId = read_u48($body, $o);
        $tmFlags = read_u16($body, $o);
        $schemaLen = read_u8($body, $o);
        $schema = read_bytes($body, $o, $schemaLen);
        $o++; // null terminator
        $tableLen = read_u8($body, $o);
        $table = read_bytes($body, $o, $tableLen);
        $o++; // null terminator
        $colCount = read_lenenc($body, $o);
        $colTypes = [];
        for ($i = 0; $i < $colCount; $i++) {
            $colTypes[] = read_u8($body, $o);
        }
        // Column metadata block
        $metaBlockLen = read_lenenc($body, $o);
        $colMeta = $this->parseColumnMetadata($colTypes, $body, $o);

        // Null bitmap
        $nullBitmapLen = intdiv($colCount + 7, 8);
        $nullBitmap = read_bytes($body, $o, $nullBitmapLen);

        // Optional metadata (MySQL 5.6+): signedness, column names, etc.
        $signedness = null;
        $colNames = null;
        if ($o < strlen($body)) {
            $parsed = $this->parseOptionalMetadata($body, $o, $colCount, $colTypes);
            $signedness = $parsed['signedness'] ?? null;
            $colNames = $parsed['column_names'] ?? null;
        }

        $this->tableMaps[$tableId] = [
            'schema' => $schema,
            'table' => $table,
            'col_count' => $colCount,
            'col_types' => $colTypes,
            'col_meta' => $colMeta,
            'null_bitmap' => $nullBitmap,
            'signedness' => $signedness,
            'col_names' => $colNames,
        ];

        // Don't emit table map events in JSON output -- they're internal bookkeeping.
        // The information surfaces when we emit the actual row changes.
        return null;
    }

    /**
     * Parse per-column metadata from the TABLE_MAP metadata block.
     * Returns an array of per-column metadata values.
     * The number of bytes consumed per column depends on its type.
     */
    private function parseColumnMetadata(array $colTypes, string $data, int &$o): array {
        $meta = [];
        foreach ($colTypes as $type) {
            switch ($type) {
                case MYSQL_TYPE_FLOAT:
                case MYSQL_TYPE_DOUBLE:
                case MYSQL_TYPE_TINY_BLOB:
                case MYSQL_TYPE_MEDIUM_BLOB:
                case MYSQL_TYPE_LONG_BLOB:
                case MYSQL_TYPE_BLOB:
                case MYSQL_TYPE_JSON:
                case MYSQL_TYPE_GEOMETRY:
                case MYSQL_TYPE_TIMESTAMP2:
                case MYSQL_TYPE_DATETIME2:
                case MYSQL_TYPE_TIME2:
                    $meta[] = read_u8($data, $o);
                    break;
                case MYSQL_TYPE_VARCHAR:
                case MYSQL_TYPE_VAR_STRING:
                case MYSQL_TYPE_BIT:
                case MYSQL_TYPE_NEWDECIMAL:
                    $meta[] = read_u16($data, $o);
                    break;
                case MYSQL_TYPE_STRING:
                    // 2 bytes: real_type + field_length
                    $meta[] = read_u16_be($data, $o);
                    break;
                case MYSQL_TYPE_ENUM:
                case MYSQL_TYPE_SET:
                    $meta[] = read_u16($data, $o);
                    break;
                default:
                    // Types with no metadata: TINY, SHORT, LONG, LONGLONG,
                    // INT24, YEAR, DATE, TIME, DATETIME, TIMESTAMP, NULL, DECIMAL
                    $meta[] = 0;
                    break;
            }
        }
        return $meta;
    }

    /**
     * Parse optional TLV metadata from TABLE_MAP event (MySQL 5.6+ / 8.0).
     * Returns an associative array with 'signedness' and 'column_names' when available.
     */
    private function parseOptionalMetadata(string $data, int &$o, int $colCount, array $colTypes): array {
        $result = [];
        $end = strlen($data);
        // If checksum is enabled, the last 4 bytes are CRC32 — but for TABLE_MAP
        // events the body was already trimmed in parseEvent, so $end is correct.

        while ($o + 2 <= $end) {
            $metaType = read_u8($data, $o);
            $metaLen = read_lenenc($data, $o);
            if ($metaLen === null || $o + $metaLen > $end) break;
            $metaStart = $o;

            if ($metaType === OPT_META_SIGNEDNESS) {
                // Bitmap: one bit per numeric column, 1 = unsigned
                $bits = read_bytes($data, $o, $metaLen);
                $signedness = [];
                $bitIdx = 0;
                for ($i = 0; $i < $colCount; $i++) {
                    if ($this->isNumericType($colTypes[$i])) {
                        $byteIdx = intdiv($bitIdx, 8);
                        $bitPos = 7 - ($bitIdx % 8); // MSB first
                        $unsigned = ($byteIdx < strlen($bits)) && ((ord($bits[$byteIdx]) >> $bitPos) & 1);
                        $signedness[$i] = (bool)$unsigned;
                        $bitIdx++;
                    }
                }
                $result['signedness'] = $signedness;
            } elseif ($metaType === OPT_META_COLUMN_NAME) {
                $names = [];
                $nameEnd = $metaStart + $metaLen;
                while ($o < $nameEnd) {
                    $names[] = read_lenenc_string($data, $o);
                }
                $result['column_names'] = $names;
            } else {
                // Skip unknown optional metadata
                $o = $metaStart + $metaLen;
            }

            // Ensure we advanced past this TLV entry
            if ($o < $metaStart + $metaLen) {
                $o = $metaStart + $metaLen;
            }
        }
        return $result;
    }

    private function isNumericType(int $type): bool {
        return in_array($type, [
            MYSQL_TYPE_TINY, MYSQL_TYPE_SHORT, MYSQL_TYPE_LONG, MYSQL_TYPE_LONGLONG,
            MYSQL_TYPE_INT24, MYSQL_TYPE_FLOAT, MYSQL_TYPE_DOUBLE, MYSQL_TYPE_NEWDECIMAL,
            MYSQL_TYPE_DECIMAL, MYSQL_TYPE_YEAR,
        ], true);
    }

    // --- Row events ---

    private function parseRowsEvent(string $body, string $ts, string $operation, bool $v2): ?array {
        $o = 0;
        $tableId = read_u48($body, $o);
        $rowFlags = read_u16($body, $o);

        // V2 events have an extra-data-length field
        if ($v2) {
            $extraLen = read_u16($body, $o);
            if ($extraLen > 2) {
                $o += $extraLen - 2; // skip extra data (already read the length itself)
            }
        }

        $numCols = read_lenenc($body, $o);
        $bitmapLen = intdiv($numCols + 7, 8);

        // Columns-present bitmap (which columns are included in the row image)
        $colsBitmapBefore = read_bytes($body, $o, $bitmapLen);
        $colsBitmapAfter = null;
        if ($operation === 'UPDATE') {
            $colsBitmapAfter = read_bytes($body, $o, $bitmapLen);
        }

        $tm = $this->tableMaps[$tableId] ?? null;
        if ($tm === null) {
            return [
                'event' => 'row_change',
                'timestamp' => $ts,
                'operation' => $operation,
                'table_id' => $tableId,
                'error' => 'No TABLE_MAP for this table_id (missed the event?)',
            ];
        }

        $tableName = $tm['schema'] . '.' . $tm['table'];

        // Decode rows
        $rows = [];
        $bodyLen = strlen($body);
        while ($o < $bodyLen) {
            if ($operation === 'UPDATE') {
                $before = $this->decodeRow($body, $o, $tm, $colsBitmapBefore, $numCols);
                $after = $this->decodeRow($body, $o, $tm, $colsBitmapAfter, $numCols);
                $rows[] = ['before' => $before, 'after' => $after];
            } else {
                $row = $this->decodeRow($body, $o, $tm, $colsBitmapBefore, $numCols);
                $rows[] = $row;
            }
        }

        return [
            'event' => 'row_change',
            'timestamp' => $ts,
            'table' => $tableName,
            'operation' => $operation,
            'rows' => $rows,
        ];
    }

    /**
     * Decode a single row from the rows event body.
     */
    private function decodeRow(string $data, int &$o, array $tm, string $colsBitmap, int $numCols): array {
        // Count how many columns are present
        $presentCols = 0;
        for ($i = 0; $i < $numCols; $i++) {
            if ((ord($colsBitmap[intdiv($i, 8)]) >> ($i % 8)) & 1) {
                $presentCols++;
            }
        }

        // Read null bitmap for present columns
        $nullBitmapLen = intdiv($presentCols + 7, 8);
        $nullBitmap = read_bytes($data, $o, $nullBitmapLen);

        $row = [];
        $presentIdx = 0;
        for ($i = 0; $i < $numCols; $i++) {
            // Column name
            $colName = ($tm['col_names'] !== null && isset($tm['col_names'][$i]))
                ? $tm['col_names'][$i]
                : "col_$i";

            // Is this column present in this image?
            if (!((ord($colsBitmap[intdiv($i, 8)]) >> ($i % 8)) & 1)) {
                // Column not in this image, skip
                continue;
            }

            // Is this column NULL?
            if ((ord($nullBitmap[intdiv($presentIdx, 8)]) >> ($presentIdx % 8)) & 1) {
                $row[$colName] = null;
                $presentIdx++;
                continue;
            }

            $row[$colName] = $this->decodeColumnValue(
                $data, $o,
                $tm['col_types'][$i],
                $tm['col_meta'][$i],
                $tm['signedness'][$i] ?? null
            );
            $presentIdx++;
        }
        return $row;
    }

    // --- Column value decoding ---

    private function decodeColumnValue(string $data, int &$o, int $type, int $meta, ?bool $unsigned): mixed {
        switch ($type) {
            case MYSQL_TYPE_TINY:
                $v = read_u8($data, $o);
                if (!$unsigned && $v >= 128) $v -= 256;
                return $v;

            case MYSQL_TYPE_SHORT:
                $v = read_u16($data, $o);
                if (!$unsigned && $v >= 32768) $v -= 65536;
                return $v;

            case MYSQL_TYPE_LONG:
                $v = read_u32($data, $o);
                if (!$unsigned && $v >= 0x80000000) $v -= 0x100000000;
                return $v;

            case MYSQL_TYPE_LONGLONG:
                $v = read_u64($data, $o);
                if ($unsigned) {
                    // PHP doesn't have unsigned 64-bit. Return as string if huge.
                    if ($v < 0) return sprintf('%u', $v);
                    return $v;
                }
                // Already signed in PHP
                return $v;

            case MYSQL_TYPE_INT24:
                $v = read_u24($data, $o);
                if (!$unsigned && $v >= 0x800000) $v -= 0x1000000;
                return $v;

            case MYSQL_TYPE_FLOAT:
                $v = unpack('g', $data, $o)[1]; // little-endian float
                $o += 4;
                return $v;

            case MYSQL_TYPE_DOUBLE:
                $v = unpack('e', $data, $o)[1]; // little-endian double
                $o += 8;
                return $v;

            case MYSQL_TYPE_YEAR:
                $v = read_u8($data, $o);
                return $v === 0 ? 0 : 1900 + $v;

            case MYSQL_TYPE_DATE:
                return $this->decodeDate($data, $o);

            case MYSQL_TYPE_TIME:
                return $this->decodeTime($data, $o);

            case MYSQL_TYPE_DATETIME:
                return $this->decodeDatetime($data, $o);

            case MYSQL_TYPE_TIMESTAMP:
                $v = read_u32($data, $o);
                return date('Y-m-d\TH:i:s\Z', $v);

            case MYSQL_TYPE_TIMESTAMP2:
                return $this->decodeTimestamp2($data, $o, $meta);

            case MYSQL_TYPE_DATETIME2:
                return $this->decodeDatetime2($data, $o, $meta);

            case MYSQL_TYPE_TIME2:
                return $this->decodeTime2($data, $o, $meta);

            case MYSQL_TYPE_NEWDECIMAL:
                return $this->decodeNewDecimal($data, $o, $meta);

            case MYSQL_TYPE_VARCHAR:
            case MYSQL_TYPE_VAR_STRING:
                // $meta is max_length; if < 256, length prefix is 1 byte, else 2
                $strLen = $meta < 256 ? read_u8($data, $o) : read_u16($data, $o);
                return read_bytes($data, $o, $strLen);

            case MYSQL_TYPE_STRING:
                return $this->decodeStringType($data, $o, $meta);

            case MYSQL_TYPE_BLOB:
            case MYSQL_TYPE_TINY_BLOB:
            case MYSQL_TYPE_MEDIUM_BLOB:
            case MYSQL_TYPE_LONG_BLOB:
            case MYSQL_TYPE_GEOMETRY:
                // $meta is the pack_length (1-4): number of bytes used for the length prefix
                return $this->decodeBlobValue($data, $o, $meta);

            case MYSQL_TYPE_JSON:
                $raw = $this->decodeBlobValue($data, $o, $meta);
                return $this->decodeMysqlBinaryJson($raw);

            case MYSQL_TYPE_BIT:
                return $this->decodeBit($data, $o, $meta);

            case MYSQL_TYPE_ENUM:
                // Pack length from meta
                $packLen = $meta & 0xFF;
                if ($packLen === 1) return read_u8($data, $o);
                return read_u16($data, $o);

            case MYSQL_TYPE_SET:
                $packLen = $meta & 0xFF;
                $v = 0;
                for ($i = 0; $i < $packLen; $i++) {
                    $v |= (read_u8($data, $o) << ($i * 8));
                }
                return $v;

            case MYSQL_TYPE_NULL:
                return null;

            default:
                // Unknown type -- read nothing, return marker
                return "(unsupported type $type)";
        }
    }

    // --- Date/time decoders ---

    private function decodeDate(string $data, int &$o): string {
        $packed = read_u24($data, $o);
        if ($packed === 0) return '0000-00-00';
        $day   = $packed & 0x1F;
        $month = ($packed >> 5) & 0x0F;
        $year  = $packed >> 9;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function decodeTime(string $data, int &$o): string {
        $packed = read_u24($data, $o);
        $sec  = $packed % 100;
        $min  = intdiv($packed, 100) % 100;
        $hour = intdiv($packed, 10000);
        return sprintf('%02d:%02d:%02d', $hour, $min, $sec);
    }

    private function decodeDatetime(string $data, int &$o): string {
        $packed = read_u64($data, $o);
        if ($packed === 0) return '0000-00-00 00:00:00';
        $timePart = $packed % 1000000;
        $datePart = intdiv($packed, 1000000);
        $sec   = $timePart % 100;
        $min   = intdiv($timePart, 100) % 100;
        $hour  = intdiv($timePart, 10000);
        $day   = $datePart % 100;
        $month = intdiv($datePart, 100) % 100;
        $year  = intdiv($datePart, 10000);
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
    }

    /**
     * TIMESTAMP2: 4 bytes big-endian seconds + fractional seconds.
     * $meta = fractional seconds precision (0-6).
     */
    private function decodeTimestamp2(string $data, int &$o, int $fsp): string {
        $secs = read_u32_be($data, $o);
        $frac = $this->readFractionalSeconds($data, $o, $fsp);
        $result = date('Y-m-d\TH:i:s', $secs);
        if ($fsp > 0) {
            $result .= '.' . str_pad($frac, $fsp, '0', STR_PAD_LEFT);
        }
        return $result . 'Z';
    }

    /**
     * DATETIME2: 5 bytes big-endian packed datetime + fractional seconds.
     * Layout (40 bits, unsigned via +0x8000000000 offset):
     *   bit 0:      sign (always 1 for valid dates)
     *   bits 1-17:  year_month = year * 13 + month  (17 bits)
     *   bits 18-22: day     (5 bits)
     *   bits 23-27: hour    (5 bits)
     *   bits 28-33: minute  (6 bits)
     *   bits 34-39: second  (6 bits)
     */
    private function decodeDatetime2(string $data, int &$o, int $fsp): string {
        $packed = read_u40_be($data, $o);
        $frac = $this->readFractionalSeconds($data, $o, $fsp);

        // Remove the sign offset
        $packed -= 0x8000000000;

        $sec       = $packed & 0x3F;    $packed >>= 6;
        $min       = $packed & 0x3F;    $packed >>= 6;
        $hour      = $packed & 0x1F;    $packed >>= 5;
        $day       = $packed & 0x1F;    $packed >>= 5;
        $yearMonth = $packed & 0x1FFFF;
        $year  = intdiv($yearMonth, 13);
        $month = $yearMonth % 13;

        $result = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
        if ($fsp > 0) {
            $result .= '.' . str_pad($frac, $fsp, '0', STR_PAD_LEFT);
        }
        return $result;
    }

    /**
     * TIME2: 3 bytes big-endian packed time + fractional seconds.
     * Layout (24 bits, unsigned via +0x800000 offset):
     *   bit 0:      sign (1 = positive)
     *   bits 1:     unused
     *   bits 2-11:  hour   (10 bits, supports up to 838)
     *   bits 12-17: minute (6 bits)
     *   bits 18-23: second (6 bits)
     */
    private function decodeTime2(string $data, int &$o, int $fsp): string {
        $packed = read_u24_be($data, $o);
        $frac = $this->readFractionalSeconds($data, $o, $fsp);

        $packed -= 0x800000;
        $negative = false;
        if ($packed < 0) {
            $negative = true;
            $packed = -$packed;
        }
        $sec  = $packed & 0x3F;   $packed >>= 6;
        $min  = $packed & 0x3F;   $packed >>= 6;
        $hour = $packed & 0x3FF;

        $result = ($negative ? '-' : '') . sprintf('%02d:%02d:%02d', $hour, $min, $sec);
        if ($fsp > 0) {
            $result .= '.' . str_pad($frac, $fsp, '0', STR_PAD_LEFT);
        }
        return $result;
    }

    /** Read fractional seconds based on precision (0-6). */
    private function readFractionalSeconds(string $data, int &$o, int $fsp): int {
        if ($fsp <= 0) return 0;
        $bytes = intdiv($fsp + 1, 2); // 1-2 fsp => 1 byte, 3-4 => 2, 5-6 => 3
        $val = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $val = ($val << 8) | ord($data[$o++]);
        }
        return $val;
    }

    // --- DECIMAL decoder ---

    /**
     * Decode NEWDECIMAL from binary log.
     * $meta encodes precision (high byte) and scale (low byte).
     */
    private function decodeNewDecimal(string $data, int &$o, int $meta): string {
        $precision = $meta & 0xFF;
        $scale = ($meta >> 8) & 0xFF;
        $intg = $precision - $scale;

        $intgFull = intdiv($intg, 9);
        $intgRem  = $intg % 9;
        $fracFull = intdiv($scale, 9);
        $fracRem  = $scale % 9;

        $digitsToBytes = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];
        $totalBytes = $digitsToBytes[$intgRem] + $intgFull * 4
                    + $fracFull * 4 + $digitsToBytes[$fracRem];

        $raw = read_bytes($data, $o, $totalBytes);

        // High bit of first byte is sign: 1 = positive
        $positive = (ord($raw[0]) & 0x80) !== 0;
        // Flip sign bit
        $raw[0] = chr(ord($raw[0]) ^ 0x80);
        // If negative, complement all bytes
        if (!$positive) {
            for ($i = 0; $i < strlen($raw); $i++) {
                $raw[$i] = chr(ord($raw[$i]) ^ 0xFF);
            }
        }

        $pos = 0;
        $intPart = '';

        // Remaining integral digits (fewer than 9)
        if ($intgRem > 0) {
            $nb = $digitsToBytes[$intgRem];
            $val = 0;
            for ($i = 0; $i < $nb; $i++) {
                $val = ($val << 8) | ord($raw[$pos++]);
            }
            $intPart .= $val;
        }

        // Full integral groups (9 digits each)
        for ($i = 0; $i < $intgFull; $i++) {
            $val = (ord($raw[$pos]) << 24) | (ord($raw[$pos+1]) << 16)
                 | (ord($raw[$pos+2]) << 8) | ord($raw[$pos+3]);
            $pos += 4;
            if ($intPart !== '') {
                $intPart .= str_pad((string)$val, 9, '0', STR_PAD_LEFT);
            } else {
                $intPart .= $val;
            }
        }

        if ($intPart === '') $intPart = '0';

        // Fractional part
        $fracPart = '';
        if ($scale > 0) {
            for ($i = 0; $i < $fracFull; $i++) {
                $val = (ord($raw[$pos]) << 24) | (ord($raw[$pos+1]) << 16)
                     | (ord($raw[$pos+2]) << 8) | ord($raw[$pos+3]);
                $pos += 4;
                $fracPart .= str_pad((string)$val, 9, '0', STR_PAD_LEFT);
            }
            if ($fracRem > 0) {
                $nb = $digitsToBytes[$fracRem];
                $val = 0;
                for ($i = 0; $i < $nb; $i++) {
                    $val = ($val << 8) | ord($raw[$pos++]);
                }
                $fracPart .= str_pad((string)$val, $fracRem, '0', STR_PAD_LEFT);
            }
        }

        $result = $intPart;
        if ($fracPart !== '') {
            $result .= '.' . $fracPart;
        }
        if (!$positive) {
            $result = '-' . $result;
        }
        return $result;
    }

    // --- STRING type decoder ---

    /**
     * MYSQL_TYPE_STRING metadata encodes the "real type" and max field length.
     * The real type can be STRING (CHAR), ENUM, or SET.
     */
    private function decodeStringType(string $data, int &$o, int $meta): mixed {
        $realType = ($meta >> 8) & 0xFF;
        $fieldLen = $meta & 0xFF;

        if ($realType === MYSQL_TYPE_ENUM) {
            if ($fieldLen === 1) return read_u8($data, $o);
            return read_u16($data, $o);
        }
        if ($realType === MYSQL_TYPE_SET) {
            $v = 0;
            for ($i = 0; $i < $fieldLen; $i++) {
                $v |= (read_u8($data, $o) << ($i * 8));
            }
            return $v;
        }

        // CHAR type: length prefix is 1 byte if max_length < 256, else 2 bytes.
        // Reconstruct actual max length (handles the XOR encoding for large CHAR).
        $maxLen = ((($realType >> 4) & 0x300) ^ 0x300) + $fieldLen;
        $strLen = ($maxLen > 255) ? read_u16($data, $o) : read_u8($data, $o);
        return read_bytes($data, $o, $strLen);
    }

    // --- BLOB decoder ---

    private function decodeBlobValue(string $data, int &$o, int $packLen): string {
        $blobLen = match ($packLen) {
            1 => read_u8($data, $o),
            2 => read_u16($data, $o),
            3 => read_u24($data, $o),
            4 => read_u32($data, $o),
            default => 0,
        };
        return read_bytes($data, $o, $blobLen);
    }

    // --- BIT decoder ---

    private function decodeBit(string $data, int &$o, int $meta): int {
        $nbits = ($meta >> 8) * 8 + ($meta & 0xFF);
        $nbytes = intdiv($nbits + 7, 8);
        $val = 0;
        for ($i = 0; $i < $nbytes; $i++) {
            $val = ($val << 8) | ord($data[$o++]);
        }
        return $val;
    }

    // --- MySQL Binary JSON decoder ---

    /**
     * Decode MySQL's binary JSON format (used in binlog row events).
     *
     * MySQL stores JSON values in an internal binary format, not as text.
     * The format is a tree of typed nodes with small/large variants for
     * objects and arrays (2-byte vs 4-byte offsets and sizes).
     */
    private function decodeMysqlBinaryJson(string $raw): mixed {
        if (strlen($raw) === 0) return null;
        $o = 0;
        $type = read_u8($raw, $o);
        return $this->readJsonValue($raw, $o, $type, strlen($raw) - 1);
    }

    private function readJsonValue(string $data, int $offset, int $type, int $len): mixed {
        $o = $offset;
        switch ($type) {
            case 0x00: // small object
                return $this->readJsonObject($data, $o, false);
            case 0x01: // large object
                return $this->readJsonObject($data, $o, true);
            case 0x02: // small array
                return $this->readJsonArray($data, $o, false);
            case 0x03: // large array
                return $this->readJsonArray($data, $o, true);
            case 0x04: // literal (true/false/null)
                $v = read_u8($data, $o);
                if ($v === 0x00) return null;
                if ($v === 0x01) return true;
                if ($v === 0x02) return false;
                return null;
            case 0x05: // int16
                $v = read_u16($data, $o);
                if ($v >= 0x8000) $v -= 0x10000;
                return $v;
            case 0x06: // uint16
                return read_u16($data, $o);
            case 0x07: // int32
                $v = read_u32($data, $o);
                if ($v >= 0x80000000) $v -= 0x100000000;
                return $v;
            case 0x08: // uint32
                return read_u32($data, $o);
            case 0x09: // int64
                return read_u64($data, $o);
            case 0x0a: // uint64
                $v = read_u64($data, $o);
                if ($v < 0) return sprintf('%u', $v);
                return $v;
            case 0x0b: // double
                $v = unpack('e', $data, $o)[1];
                return $v;
            case 0x0c: // string
                $strLen = $this->readJsonVarint($data, $o);
                return substr($data, $o, $strLen);
            case 0x0f: // opaque
                return '(opaque binary, ' . $len . ' bytes)';
            default:
                return '(unknown json type 0x' . dechex($type) . ')';
        }
    }

    private function readJsonObject(string $data, int $o, bool $large): array {
        $intSize = $large ? 4 : 2;
        $elemCount = $large ? read_u32($data, $o) : read_u16($data, $o);
        $totalSize = $large ? read_u32($data, $o) : read_u16($data, $o);

        // Key entries: each has offset to key data + key length
        $keyEntries = [];
        for ($i = 0; $i < $elemCount; $i++) {
            $keyOffset = $large ? read_u32($data, $o) : read_u16($data, $o);
            $keyLen = read_u16($data, $o);
            $keyEntries[] = [$keyOffset, $keyLen];
        }

        // Value entries: each has type byte + inline value or offset
        $valueEntries = [];
        for ($i = 0; $i < $elemCount; $i++) {
            $vtype = read_u8($data, $o);
            $inlined = $this->isJsonInlineable($vtype, $large);
            if ($inlined) {
                $inlineBytes = read_bytes($data, $o, $intSize);
                $valueEntries[] = ['type' => $vtype, 'inline' => $inlineBytes];
            } else {
                $voffset = $large ? read_u32($data, $o) : read_u16($data, $o);
                $valueEntries[] = ['type' => $vtype, 'offset' => $voffset];
            }
        }

        // The offsets in key/value entries are relative to the start of the
        // object/array container (the byte right after the type byte in the
        // top-level value, i.e. the element-count position).
        $baseOffset = $o - (2 * $intSize) // elem_count + total_size
                        - ($elemCount * ($intSize + 2)) // key entries
                        - ($elemCount * (1 + $intSize)); // value entries

        $result = [];
        for ($i = 0; $i < $elemCount; $i++) {
            // Read key string
            $keyOff = $keyEntries[$i][0] + $baseOffset;
            $keyStr = substr($data, $keyOff, $keyEntries[$i][1]);

            // Read value
            $ve = $valueEntries[$i];
            if (isset($ve['inline'])) {
                $io = 0;
                $val = $this->readJsonInlineValue($ve['inline'], $io, $ve['type'], $large);
            } else {
                $val = $this->readJsonValue($data, $ve['offset'] + $baseOffset, $ve['type'], 0);
            }
            $result[$keyStr] = $val;
        }
        return $result;
    }

    private function readJsonArray(string $data, int $o, bool $large): array {
        $intSize = $large ? 4 : 2;
        $elemCount = $large ? read_u32($data, $o) : read_u16($data, $o);
        $totalSize = $large ? read_u32($data, $o) : read_u16($data, $o);

        $valueEntries = [];
        for ($i = 0; $i < $elemCount; $i++) {
            $vtype = read_u8($data, $o);
            $inlined = $this->isJsonInlineable($vtype, $large);
            if ($inlined) {
                $inlineBytes = read_bytes($data, $o, $intSize);
                $valueEntries[] = ['type' => $vtype, 'inline' => $inlineBytes];
            } else {
                $voffset = $large ? read_u32($data, $o) : read_u16($data, $o);
                $valueEntries[] = ['type' => $vtype, 'offset' => $voffset];
            }
        }

        $baseOffset = $o - (2 * $intSize) - ($elemCount * (1 + $intSize));

        $result = [];
        for ($i = 0; $i < $elemCount; $i++) {
            $ve = $valueEntries[$i];
            if (isset($ve['inline'])) {
                $io = 0;
                $result[] = $this->readJsonInlineValue($ve['inline'], $io, $ve['type'], $large);
            } else {
                $result[] = $this->readJsonValue($data, $ve['offset'] + $baseOffset, $ve['type'], 0);
            }
        }
        return $result;
    }

    /**
     * Small containers can inline values that fit in 2 bytes (LITERAL, INT16, UINT16).
     * Large containers can inline values that fit in 4 bytes (all of the above + INT32, UINT32).
     */
    private function isJsonInlineable(int $type, bool $large): bool {
        if ($type === 0x04) return true; // literal
        if ($type === 0x05 || $type === 0x06) return true; // int16/uint16
        if ($large && ($type === 0x07 || $type === 0x08)) return true; // int32/uint32 in large containers
        return false;
    }

    private function readJsonInlineValue(string $bytes, int &$o, int $type, bool $large): mixed {
        switch ($type) {
            case 0x04: // literal
                $v = read_u8($bytes, $o);
                if ($v === 0x00) return null;
                if ($v === 0x01) return true;
                return false;
            case 0x05: // int16
                $v = read_u16($bytes, $o);
                if ($v >= 0x8000) $v -= 0x10000;
                return $v;
            case 0x06: // uint16
                return read_u16($bytes, $o);
            case 0x07: // int32
                $v = read_u32($bytes, $o);
                if ($v >= 0x80000000) $v -= 0x100000000;
                return $v;
            case 0x08: // uint32
                return read_u32($bytes, $o);
            default:
                return null;
        }
    }

    /** MySQL binary JSON uses a variable-length integer (LEB128-style). */
    private function readJsonVarint(string $data, int &$o): int {
        $val = 0;
        $shift = 0;
        while (true) {
            $byte = ord($data[$o++]);
            $val |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) break;
            $shift += 7;
        }
        return $val;
    }

    // --- Other event parsers ---

    private function parseQueryEvent(string $body, string $ts): array {
        $o = 0;
        $threadId = read_u32($body, $o);
        $execTime = read_u32($body, $o);
        $dbLen = read_u8($body, $o);
        $errCode = read_u16($body, $o);
        $statusVarsLen = read_u16($body, $o);
        $o += $statusVarsLen; // skip status variables
        $database = read_bytes($body, $o, $dbLen);
        $o++; // null terminator
        $sql = substr($body, $o);
        return [
            'event' => 'query',
            'timestamp' => $ts,
            'database' => $database,
            'sql' => $sql,
            'execution_time_sec' => $execTime,
        ];
    }

    private function parseXidEvent(string $body, string $ts): array {
        $o = 0;
        $xid = read_u64($body, $o);
        return ['event' => 'xid', 'timestamp' => $ts, 'xid' => $xid];
    }

    private function parseRotateEvent(string $body, string $ts): array {
        $o = 0;
        $position = read_u64($body, $o);
        $filename = substr($body, $o);
        fwrite(STDERR, "Rotating to binlog file: $filename at position $position\n");
        return [
            'event' => 'rotate',
            'timestamp' => $ts,
            'next_file' => $filename,
            'next_position' => $position,
        ];
    }

    private function parseGtidEvent(string $body, string $ts): ?array {
        if (strlen($body) < 42) return null;
        $o = 0;
        $commitFlag = read_u8($body, $o);
        // UUID is stored as 16 raw bytes
        $uuidBytes = read_bytes($body, $o, 16);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($uuidBytes, 0, 4)),
            bin2hex(substr($uuidBytes, 4, 2)),
            bin2hex(substr($uuidBytes, 6, 2)),
            bin2hex(substr($uuidBytes, 8, 2)),
            bin2hex(substr($uuidBytes, 10, 6))
        );
        $gno = read_u64($body, $o);
        return [
            'event' => 'gtid',
            'timestamp' => $ts,
            'gtid' => "$uuid:$gno",
            'commit' => (bool)$commitFlag,
        ];
    }
}

// ================================================================
// Main
// ================================================================

$opts = getopt('', [
    'host:',
    'port:',
    'user:',
    'password:',
    'binlog-file:',
    'binlog-pos:',
    'server-id:',
    'help',
]);

if (isset($opts['help']) || $opts === false) {
    fwrite(STDERR, <<<USAGE
    Usage: php binlog-json.php [OPTIONS]

    Options:
      --host=HOST          MySQL host (default: 127.0.0.1)
      --port=PORT          MySQL port (default: 3306)
      --user=USER          MySQL user (default: root)
      --password=PASS      MySQL password (default: empty)
      --binlog-file=FILE   Binlog filename to start from (default: auto-detect)
      --binlog-pos=POS     Binlog position to start from (default: 4)
      --server-id=ID       Replica server ID (default: 999)
      --help               Show this message

    Output: one JSON object per line to stdout. Progress info to stderr.

    USAGE);
    exit(0);
}

$host       = $opts['host']        ?? '127.0.0.1';
$port       = (int)($opts['port']  ?? 3306);
$user       = $opts['user']        ?? 'root';
$password   = $opts['password']    ?? '';
$binlogFile = $opts['binlog-file'] ?? '';
$binlogPos  = (int)($opts['binlog-pos'] ?? 4);
$serverId   = (int)($opts['server-id'] ?? 999);

$client = new MysqlBinlogClient();
try {
    $client->connect($host, $port);
    $client->authenticate($user, $password);
    $client->startBinlogDump($serverId, $binlogFile, $binlogPos);
    $client->eventLoop();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
