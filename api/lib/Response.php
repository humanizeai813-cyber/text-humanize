<?php
/**
 * Response — Standardized JSON envelope for all API endpoints.
 *
 * Every response has the shape:
 *   { "ok": bool, "data": {...}, "meta": { "requestId": "...", "durationMs": N } }
 *
 * Error responses:
 *   { "ok": false, "error": { "code": "...", "message": "..." }, "meta": {...} }
 */
class Response
{
    private float  $startTime;
    private string $requestId;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->requestId = $this->generateId();
    }

    public function success(array $data, int $status = 200): void
    {
        $this->send([
            'ok'   => true,
            'data' => $data,
            'meta' => $this->meta(),
        ], $status);
    }

    public function error(string $code, string $message, int $status = 400, array $extra = []): void
    {
        $this->send([
            'ok'    => false,
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
            'meta'  => $this->meta(),
        ], $status);
    }

    private function send(array $payload, int $status): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function meta(): array
    {
        return [
            'requestId'  => $this->requestId,
            'durationMs' => (int) round((microtime(true) - $this->startTime) * 1000),
        ];
    }

    private function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
