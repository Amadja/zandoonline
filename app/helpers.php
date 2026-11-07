<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    global $config;
    header('Location: ' . rtrim($config['base_url'], '/') . '/' . ltrim($path, '/'));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verify_csrf(): void
{
    $submitted = $_POST['_csrf'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Your form session expired. Please go back and try again.');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return is_string($value) ? $value : null;
}

function old(string $key): string
{
    return e(isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '');
}

function normalize_ugandan_phone(string $value): ?string
{
    $digits = preg_replace('/\D+/', '', trim($value));
    if (!is_string($digits) || $digits === '') {
        return null;
    }

    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '0')) {
        $digits = '256' . substr($digits, 1);
    } elseif (!str_starts_with($digits, '256')) {
        $digits = '256' . $digits;
    }

    return preg_match('/^256[0-9]{9}$/', $digits) ? '+' . $digits : null;
}
