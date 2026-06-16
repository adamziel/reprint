<?php

if (!class_exists('PreserveLocalSkipException', false)) {
    /**
     * Thrown by ensure_directory_path() in preserve-local mode when a directory
     * component is not writable or a symlink blocks directory creation.
     * Callers catch this to skip the current file/directory/symlink gracefully.
     */
    class PreserveLocalSkipException extends RuntimeException {}
}

if (!class_exists('CurlTimeoutException', false)) {
    /**
     * Thrown when a cURL request times out (CURLE_OPERATION_TIMEDOUT).
     * Callers catch this to save state and exit with "partial" status instead
     * of crashing with a fatal error — the next invocation resumes from the
     * last saved cursor.
     */
    class CurlTimeoutException extends RuntimeException {}
}
