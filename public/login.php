<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$signedInUser = current_user();
if ($signedInUser) redirect($signedInUser['role'] === 'delivery' ? 'delivery_app.php' : 'dashboard.php');

$error = null;
$identifier = '';
if (is_post()) {
    verify_csrf();
    $identifier = trim(is_string($_POST['identifier'] ?? null) ? $_POST['identifier'] : '');
    $password = (string) ($_POST['password'] ?? '');

    $record = false;
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $stmt = db()->prepare('SELECT id, password_hash, status, role FROM users WHERE email = :identifier LIMIT 1');
        $stmt->execute(['identifier' => strtolower($identifier)]);
        $record = $stmt->fetch();
    } elseif ($phone = normalize_ugandan_phone($identifier)) {
        $stmt = db()->prepare('SELECT id, password_hash, status, role FROM users WHERE phone = :identifier LIMIT 1');
        $stmt->execute(['identifier' => $phone]);
        $record = $stmt->fetch();
    }

    if ($record && $record['status'] === 'active' && password_verify($password, $record['password_hash'])) {
        if (password_needs_rehash($record['password_hash'], PASSWORD_DEFAULT)) {
            $update = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $record['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $record['id'];
        flash('success', 'Welcome back to Zandoonline.');
        redirect($record['role'] === 'delivery' ? 'delivery_app.php' : 'dashboard.php');
    }

    $error = 'The email, phone number, or password is incorrect, or the account is not active.';
}

$pageTitle = 'Log in';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="form-wrap">
    <h1>Welcome back</h1>
    <p class="muted">Log in to your Zandoonline account.</p>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-card">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>Email or phone number<input type="text" name="identifier" required autocomplete="username" placeholder="Email address or 0700 123 456" value="<?= e($identifier) ?>"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="button primary full" type="submit">Log in</button>
    </form>
    <p class="center muted">New to Zandoonline? <a href="<?= e($config['base_url']) ?>/register.php">Create an account</a></p>
</section>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>
