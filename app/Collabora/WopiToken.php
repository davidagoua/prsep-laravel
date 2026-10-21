<?php

namespace App\Collabora;

use Illuminate\Support\Str;

/**
 * Jeton d'accès WOPI : document, utilisateur, droit d'écriture et expiration, signé avec la clé de l'application.
 * Collabora le renvoie à chaque appel ; aucune session n'est utilisée.
 */
class WopiToken
{
    public const TTL_SECONDS = 36000;

    /** @return array{token: string, expires_ms: int} */
    public static function issue(string $file, int $userId, bool $write): array
    {
        $expires = now()->addSeconds(self::TTL_SECONDS)->timestamp;
        $payload = rtrim(strtr(base64_encode(json_encode(['f' => $file, 'u' => $userId, 'w' => $write, 'e' => $expires])), '+/', '-_'), '=');

        return ['token' => $payload.'.'.self::sign($payload), 'expires_ms' => $expires * 1000];
    }

    /** @return array{f: string, u: int, w: bool, e: int}|null */
    public static function verify(?string $token, string $file): ?array
    {
        if (! $token || ! str_contains($token, '.')) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);
        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($data) && ($data['f'] ?? null) === $file && ($data['e'] ?? 0) > now()->timestamp ? $data : null;
    }

    private static function sign(string $payload): string
    {
        $key = config('app.key');
        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return hash_hmac('sha256', 'wopi|'.$payload, $key);
    }
}
