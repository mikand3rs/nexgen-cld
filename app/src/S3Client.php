<?php
// BMOS 3.2 — Phase 7: minimal S3-compatible client for Garage.
// Supports presigned GET (download) and PUT (upload) URLs, plus HEAD for
// verification. Uses AWS Signature Version 4.
//
// DESIGN: PHP never proxies bytes. The flow is:
//   1. Client calls POST /objects → BMOS authorizes, creates the PG record
//      (state=pending), returns a presigned PUT URL.
//   2. Client PUTs bytes directly to Garage via that URL (short-lived).
//   3. Client calls POST /objects/{id}/complete → BMOS HEADs the object to
//      verify size/checksum, flips state to uploaded.
//   4. Client calls GET /objects/{id}/download → BMOS authorizes (tenant
//      RLS), returns a presigned GET URL.
//   5. Client GETs bytes directly from Garage.
//
// Credentials are the non-root bmos_app Garage user (from secrets), never
// the Garage root identity.

final class S3Client
{
    private string $endpoint;   // e.g. http://garage:9000
    private string $region;
    private string $accessKey;
    private string $secretKey;

    public function __construct()
    {
        $this->endpoint = rtrim(getenv('S3_ENDPOINT') ?: 'http://garage:3900', '/');
        $this->region = getenv('S3_REGION') ?: 'garage';
        $keyFile = getenv('S3_KEY_ID_FILE') ?: '/run/secrets/garage_app_key_id';
        $secFile = getenv('S3_SECRET_FILE') ?: '/run/secrets/garage_app_secret';
        $this->accessKey = is_readable($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
        $this->secretKey = is_readable($secFile) ? trim((string) file_get_contents($secFile)) : '';
        if ($this->accessKey === '' || $this->secretKey === '') {
            throw new RuntimeException('S3 app credentials not available');
        }
    }

    /** HMAC-SHA256 helper. */
    private static function hmac(string $key, string $data, bool $raw = true): string
    {
        return hash_hmac('sha256', $data, $key, $raw);
    }

    /** Derive the signing key for the date/region/service. */
    private function signingKey(string $dateStamp): string
    {
        $kDate = self::hmac('AWS4' . $this->secretKey, $dateStamp);
        $kRegion = self::hmac($kDate, $this->region);
        $kService = self::hmac($kRegion, 's3');
        return self::hmac($kService, 'aws4_request');
    }

    /**
     * Build a presigned URL for GET (download) or PUT (upload).
     * $expires: seconds the URL stays valid (short-lived by design).
     */
    public function presign(string $method, string $bucket, string $key, int $expires = 900): string
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'PUT'], true)) {
            throw new InvalidArgumentException('presign supports GET and PUT only');
        }
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $hostHeader = $port ? "{$host}:{$port}" : $host;

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";

        // Query params (must be sorted for signing).
        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($params);
        $canonicalQuery = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $canonicalUri = '/' . $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonicalHeaders = "host:{$hostHeader}\n";
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = implode("\n", [
            $method, $canonicalUri, $canonicalQuery,
            $canonicalHeaders, 'host', $payloadHash,
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256', $amzDate, $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp), false);

        return "{$this->endpoint}{$canonicalUri}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    public function presignGet(string $bucket, string $key, int $expires = 900): string
    {
        return $this->presign('GET', $bucket, $key, $expires);
    }

    public function presignPut(string $bucket, string $key, int $expires = 900): string
    {
        return $this->presign('PUT', $bucket, $key, $expires);
    }

    /**
     * HEAD an object to verify it exists and get its size/ETag.
     * Uses the app credentials (Authorization header, not presigned).
     * @return array{size: int, etag: string}|null — null if not found.
     */
    /**
     * Verify an object exists via a presigned GET URL with a byte-range
     * request (avoids header-SigV4; S3 requires the method to match the
     * signature, so a HEAD to a presigned GET URL is 403).
     * Returns size or null if 404.
     */
    public function headObjectViaPresigned(string $bucket, string $key): ?array
    {
        $url = $this->presignGet($bucket, $key, 60);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RANGE, '0-0'); // first byte only
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 404) {
            return null;
        }
        if ($code !== 206 && $code !== 200) {
            throw new RuntimeException("Garage range-GET failed: HTTP {$code}");
        }
        // Parse total size from Content-Range: bytes 0-0/1234
        $size = 0;
        if (preg_match('/Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/i', $response, $m)) {
            $size = (int) $m[1];
        }
        return ['size' => $size];
    }

    /** Delete an object (used on object delete). */
    public function deleteObject(string $bucket, string $key): void
    {
        // Signed DELETE via the same header-auth pattern as headObject.
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $hostHeader = $port ? "{$host}:{$port}" : $host;
        $uri = '/' . $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');
        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";

        $canonicalRequest = implode("\n", [
            'DELETE', $uri, '',
            "host:{$hostHeader}\n" . "x-amz-date:{$amzDate}\n", 'host;x-amz-date',
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ]);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp), false);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, "
            . "SignedHeaders=host;x-amz-date, Signature={$signature}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint . $uri);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$hostHeader}",
            "X-Amz-Date: {$amzDate}",
            "Authorization: {$auth}",
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 204 && $code !== 200) {
            throw new RuntimeException("Garage DELETE failed: HTTP {$code}");
        }
    }
}
