<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Document ingestion (library, used by the worker only)
|--------------------------------------------------------------------------
|
| Supported file types for v2 (recorded decision):
|   .txt, .md  — read directly
|   .pdf       — pdftotext if available in the container, else a
|                pure-PHP fallback that extracts FlateDecode text
|                streams from simple PDFs. If neither yields text,
|                the job fails visibly (not silently) with a clear
|                reason, so the operator knows that PDF needs
|                pdftotext or a different source file.
| Out of scope for v2: scanned-image OCR, .docx, spreadsheets.
| Those files are rejected at upload with a clear reason, not
| silently stored as unreadable.
|
| Chunking: overlapping chunks, ~1200 characters with ~200 overlap,
| split on paragraph / sentence boundaries where possible. Every
| chunk keeps document_id + chunk_index so answers can cite sources.
| Chunking is a pure function (chunk_text) and is unit-tested in
| app/tests/run-tests.php without a database.
*/

const CLD_MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB
const CLD_ALLOWED_EXTENSIONS = ['txt', 'md', 'pdf'];
const CLD_ALLOWED_COLLECTIONS = ['general', 'operations', 'website', 'support', 'admin'];

/**
 * Validate an uploaded file, in this order (V2.1 rule):
 * upload error, size, extension allowlist, actual type (finfo),
 * empty file. Returns an error string, or '' if valid.
 */
function ingestion_validate_upload(array $file): string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed. Please try again.';
    }
    if (!isset($file['size']) || $file['size'] <= 0) {
        return 'That file is empty.';
    }
    if ($file['size'] > CLD_MAX_UPLOAD_BYTES) {
        return 'That file is larger than 10 MB.';
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, CLD_ALLOWED_EXTENSIONS, true)) {
        return 'Only .txt, .md, and .pdf files are accepted in v2.';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return 'Could not check the file type. Please try again.';
    }
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    // finfo is a sanity check, not a security boundary on its own:
    // .txt/.md are usually text/plain, .pdf is application/pdf,
    // but some plain-text files are reported as application/octet-stream.
    $allowedMimes = [
        'txt' => ['text/plain', 'application/octet-stream'],
        'md' => ['text/plain', 'application/octet-stream'],
        'pdf' => ['application/pdf'],
    ];
    if (!in_array($mime, $allowedMimes[$ext] ?? [], true)) {
        return 'That file does not look like a .' . $ext . ' file (detected: ' . $mime . ').';
    }
    return '';
}

/**
 * Generate a safe stored filename. Never trust the supplied name:
 * the stored name is <doc_id>.<ext>, in a tenant + document folder.
 */
function ingestion_stored_name(int $documentId, string $extension): string
{
    return $documentId . '.' . strtolower($extension);
}

/**
 * Extract plain text from a stored file. Returns '' on failure;
 * the caller turns that into a visible job failure.
 */
function ingestion_extract_text(string $path, string $extension): string
{
    $extension = strtolower($extension);
    if ($extension === 'txt' || $extension === 'md') {
        $text = file_get_contents($path);
        return is_string($text) ? $text : '';
    }
    if ($extension === 'pdf') {
        // Prefer pdftotext when the image provides it (see Dockerfile).
        $escaped = escapeshellarg($path);
        $out = [];
        $code = 0;
        // @codingStandardsIgnoreStart — worker-only, path is escaped.
        @exec('pdftotext -layout ' . $escaped . ' - 2>/dev/null', $out, $code);
        // @codingStandardsIgnoreEnd
        if ($code === 0 && $out !== []) {
            return implode("\n", $out);
        }
        return ingestion_extract_pdf_fallback($path);
    }
    return '';
}

/**
 * Pure-PHP PDF fallback: extract text from FlateDecode streams.
 * Works for many generated PDFs; fails cleanly (returns '') for
 * scanned, encrypted, or heavily compressed PDFs — which then
 * fail visibly in the worker, rather than indexing garbage.
 */
function ingestion_extract_pdf_fallback(string $path): string
{
    $data = file_get_contents($path);
    if (!is_string($data) || $data === '') {
        return '';
    }
    $texts = [];
    if (preg_match_all('/stream(.*?)endstream/s', $data, $matches)) {
        foreach ($matches[1] as $stream) {
            $stream = trim($stream);
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = $stream;
            }
            if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $decoded, $parts)) {
                foreach ($parts[0] as $part) {
                    $inner = substr($part, 1, -1);
                    $inner = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $inner);
                    $texts[] = $inner;
                }
            }
        }
    }
    $text = implode(' ', $texts);
    // Keep only printable text, collapse whitespace.
    $text = preg_replace('/[^\P{C}\n\t ]+/u', '', $text) ?? '';
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

/**
 * Multibyte-safe string helpers with a byte fallback, so chunking
 * works whether or not the mbstring extension is installed.
 * (Dockerfile installs mbstring; this fallback keeps the code
 * running on a minimal PHP build instead of fataling.)
 */
function cld_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}
function cld_substr(string $s, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($s, $start) : mb_substr($s, $start, $length);
    }
    return $length === null ? substr($s, $start) : substr($s, $start, $length);
}
function cld_strrpos(string $haystack, string $needle): int|false
{
    return function_exists('mb_strrpos') ? mb_strrpos($haystack, $needle) : strrpos($haystack, $needle);
}

/**
 * Split text into overlapping chunks. Pure function, tested.
 * Chunk size ~1200 chars, overlap ~200, prefer paragraph breaks.
 *
 * @return string[]
 */
function chunk_text(string $text, int $chunkSize = 1200, int $overlap = 200): array
{
    $text = trim(preg_replace('/\r\n?/', "\n", $text) ?? '');
    if ($text === '') {
        return [];
    }
    if (cld_strlen($text) <= $chunkSize) {
        return [$text];
    }
    $chunks = [];
    $pos = 0;
    $len = cld_strlen($text);
    while ($pos < $len) {
        $slice = cld_substr($text, $pos, $chunkSize);
        // Prefer to end at a paragraph break inside the slice.
        $break = cld_strrpos($slice, "\n\n");
        if ($break !== false && $break > (int) ($chunkSize * 0.5)) {
            $slice = cld_substr($slice, 0, $break);
        }
        $slice = trim($slice);
        if ($slice !== '') {
            $chunks[] = $slice;
        }
        $pos += max(1, cld_strlen($slice) - $overlap);
        // Guard against a zero-length advance on whitespace-only slices.
        if ($slice === '') {
            $pos++;
        }
    }
    return $chunks;
}

/**
 * Ingest one document (worker only, inside a transaction per chunk
 * batch). Steps: mark processing, extract, chunk, replace old chunks,
 * insert new chunks with tsvector, mark indexed / failed / partial.
 * Returns true on indexed, false on failed (error is in the DB).
 */
function ingestion_process_document(PDO $pdo, int $tenantId, int $documentId): bool
{
    $stmt = $pdo->prepare(
        'SELECT * FROM documents WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$documentId, $tenantId]);
    $doc = $stmt->fetch();
    if (!is_array($doc)) {
        return false;
    }

    $pdo->prepare(
        "UPDATE documents SET status = 'processing', error = NULL, updated_at = now() WHERE id = ?"
    )->execute([$documentId]);

    $path = storage_root() . '/' . $tenantId . '/' . $documentId . '/' . $doc['stored_name'];
    $ext = strtolower(pathinfo($doc['original_name'], PATHINFO_EXTENSION));
    $text = is_file($path) ? ingestion_extract_text($path, $ext) : '';

    if (trim($text) === '') {
        $pdo->prepare(
            "UPDATE documents SET status = 'failed', error = ?, updated_at = now() WHERE id = ?"
        )->execute(['No readable text could be extracted from this file. For PDFs, install poppler-utils (pdftotext) or upload a .txt/.md version.', $documentId]);
        return false;
    }

    $chunks = chunk_text($text);
    if ($chunks === []) {
        $pdo->prepare(
            "UPDATE documents SET status = 'failed', error = ?, updated_at = now() WHERE id = ?"
        )->execute(['No readable text could be extracted from this file.', $documentId]);
        return false;
    }

    $pdo->beginTransaction();
    try {
        db_set_tenant($pdo, $tenantId);
        $pdo->prepare('DELETE FROM doc_chunks WHERE document_id = ? AND tenant_id = ?')
            ->execute([$documentId, $tenantId]);
        $insert = $pdo->prepare(
            "INSERT INTO doc_chunks
             (tenant_id, document_id, chunk_index, content, content_tsv)
             VALUES (?, ?, ?, ?, to_tsvector('english', ?))"
        );
        foreach ($chunks as $i => $chunk) {
            $insert->execute([$tenantId, $documentId, $i, $chunk, $chunk]);
        }
        $pdo->prepare(
            "UPDATE documents SET status = 'indexed', error = NULL, updated_at = now() WHERE id = ?"
        )->execute([$documentId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('NexGen CLD ingestion failed for document ' . $documentId . ': ' . $e->getMessage());
        $pdo->prepare(
            "UPDATE documents SET status = 'failed', error = ?, updated_at = now() WHERE id = ?"
        )->execute(['Ingestion failed. See the server log for details.', $documentId]);
        return false;
    }
}
