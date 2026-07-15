<?php

declare(strict_types=1);

namespace KurseInformatike\Shared\Http;

final class Csrf
{
    public const SESSION_KEY = 'csrf_token';
    public const FIELD = 'csrf_token';
    public const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function requestToken(): string
    {
        $header = $_SERVER[self::HEADER] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $field = $_POST[self::FIELD] ?? '';
        return is_string($field) ? $field : '';
    }

    public static function isValid(?string $candidate = null): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        $candidate ??= self::requestToken();

        return is_string($expected)
            && $expected !== ''
            && $candidate !== ''
            && hash_equals($expected, $candidate);
    }

    public static function requireValid(?string $candidate = null): void
    {
        if (!self::isValid($candidate)) {
            throw new \DomainException('Sesioni ka skaduar. Ringarkoni faqen dhe provoni përsëri.');
        }
    }

    public static function regenerate(): string
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::SESSION_KEY];
    }
}
