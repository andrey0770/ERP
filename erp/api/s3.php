<?php
/**
 * S3-совместимый клиент (Yandex Object Storage)
 * AWS Signature V4, чистый PHP без SDK
 */
class S3Client {
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $prefix;

    public function __construct(array $cfg) {
        $this->endpoint  = rtrim($cfg['endpoint'], '/');
        $this->region    = $cfg['region'];
        $this->bucket    = $cfg['bucket'];
        $this->accessKey = $cfg['access_key'];
        $this->secretKey = $cfg['secret_key'];
        $this->prefix    = $cfg['erp_prefix'] ?? 'erp/';
    }

    /**
     * Загрузить файл в S3
     * @return string Публичная ссылка на файл
     */
    public function upload(string $key, string $body, string $contentType = 'application/octet-stream'): string {
        $fullKey = $this->prefix . $key;
        // Virtual-hosted style: https://bucket.storage.yandexcloud.net/key
        $host = "{$this->bucket}.storage.yandexcloud.net";
        $url = "https://{$host}/{$fullKey}";

        $headers = $this->signRequest('PUT', $fullKey, $body, $contentType, $host);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("S3 upload failed: HTTP {$httpCode} — {$response} {$error}");
        }

        return "https://{$host}/{$fullKey}";
    }

    /**
     * Удалить файл из S3
     */
    public function delete(string $key): void {
        $fullKey = $this->prefix . $key;
        $host = "{$this->bucket}.storage.yandexcloud.net";
        $url = "https://{$host}/{$fullKey}";

        $headers = $this->signRequest('DELETE', $fullKey, '', '', $host);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * AWS Signature V4
     */
    private function signRequest(string $method, string $key, string $body, string $contentType, string $host): array {
        $now = new DateTime('UTC');
        $dateStamp = $now->format('Ymd');
        $amzDate   = $now->format('Ymd\THis\Z');
        $service   = 's3';

        $payloadHash = hash('sha256', $body);
        // Virtual-hosted style: canonical URI = /key (no bucket)
        $encodedKey  = '/' . implode('/', array_map('rawurlencode', explode('/', $key)));

        $canonHeaders = "host:{$host}\n" .
                        "x-amz-content-sha256:{$payloadHash}\n" .
                        "x-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonRequest = "{$method}\n{$encodedKey}\n\n{$canonHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $scope = "{$dateStamp}/{$this->region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', $service,
                hash_hmac('sha256', $this->region,
                    hash_hmac('sha256', $dateStamp, "AWS4{$this->secretKey}", true),
                true),
            true),
        true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $auth = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headers = [
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: {$payloadHash}",
            "Authorization: {$auth}",
        ];
        if ($contentType) {
            $headers[] = "Content-Type: {$contentType}";
        }

        return $headers;
    }

    /**
     * Сгенерировать уникальный ключ для файла
     */
    public static function generateKey(string $entityType, int $entityId, string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($filename, PATHINFO_FILENAME));
        $uid = substr(bin2hex(random_bytes(4)), 0, 8);
        return "attachments/{$entityType}/{$entityId}/{$uid}_{$safe}.{$ext}";
    }
}
