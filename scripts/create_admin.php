<?php
declare(strict_types=1);

/*
 * Run from CLI only:
 * php scripts/create_admin.php "Admin Name" admin@example.com "Long-Unique-Password" "0770 123 456"
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

if ($argc < 4 || $argc > 5) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php \"Full Name\" email password [phone]\n");
    exit(1);
}

[, $name, $email, $password] = $argv;
$email = strtolower(trim($email));
$phoneInput = $argv[4] ?? '';
$phone = $phoneInput !== '' ? normalize_ugandan_phone($phoneInput) : null;

if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 14 || ($phoneInput !== '' && $phone === null)) {
    fwrite(STDERR, "Provide a valid name/email, a password of at least 14 characters, and a valid Ugandan phone number if supplied.\n");
    exit(1);
}

try {
    $stmt = db()->prepare(
        "INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (:name, :email, :phone, :hash, 'admin')"
    );
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    fwrite(STDOUT, "Administrator created successfully.\n");
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fwrite(STDERR, "That email address or phone number is already registered.\n");
    } else {
        error_log($e->getMessage());
        fwrite(STDERR, "Could not create administrator. Check database configuration.\n");
    }
    exit(1);
}
