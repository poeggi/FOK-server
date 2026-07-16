<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Db.php';

final class Util
{
    // Player IDs are 32-bit values as 8 lowercase hex chars (see FOK-snake js/storage.js).
    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}$/', $id) === 1;
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '?';
    }

    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, FOK_ALLOWED_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 3600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw === false ? '' : $raw, true);
        return is_array($data) ? $data : [];
    }

    public static function jsonOut(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function fail(string $msg, int $code = 400): never
    {
        self::jsonOut(['ok' => false, 'error' => $msg], $code);
    }

    // Per-hour counters feed the admin load statistics.
    public static function bump(string $metric): void
    {
        $bucket = gmdate('YmdH');
        Db::get()->prepare(
            'INSERT INTO counters (bucket, metric, value) VALUES (?, ?, 1)
             ON CONFLICT (bucket, metric) DO UPDATE SET value = value + 1'
        )->execute([$bucket, $metric]);
    }
}
