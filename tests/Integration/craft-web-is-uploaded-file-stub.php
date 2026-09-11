<?php

namespace craft\web;

/**
 * Test-only shim for SmartTableImportControllerCest.
 *
 * craft\web\UploadedFile::getInstanceByName() calls the global
 * is_uploaded_file() to confirm a file arrived via a genuine PHP-parsed
 * upload — true only for a real HTTP request, which the Codeception
 * integration suite never sends (it calls the controller action directly
 * against a manually populated $_FILES). Defining is_uploaded_file() here,
 * inside the same `craft\web` namespace UploadedFile.php itself lives in,
 * shadows the global built-in for every *unqualified* call made from that
 * namespace (PHP's namespaced-function-falls-back-to-global rule works in
 * reverse too: a namespace-local function always wins over the global one),
 * letting a manually staged temp file stand in for a real upload.
 *
 * Never loaded by the plugin's own src/ — only required directly by the
 * Cest that needs it.
 */
function is_uploaded_file(string $filename): bool
{
    return file_exists($filename);
}
