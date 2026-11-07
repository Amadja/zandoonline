<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$errors = [];
if (is_post()) {
    verify_csrf();
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phoneInput = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');
    $phone = normalize_ugandan_phone($phoneInput);
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'customer');
    $businessName = trim((string) ($_POST['business_name'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $errors[] = 'Enter your full name (2–120 characters).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Enter a valid email address.';
    if ($phone === null) $errors[] = 'Enter a valid Ugandan phone number, such as 0700 123 456.';
    if (strlen($password) < 10) $errors[] = 'Your password must be at least 10 characters.';
    if (!in_array($role, ['customer', 'seller'], true)) $errors[] = 'Choose a valid account type.';
    if ($role === 'seller' && mb_strlen($businessName) < 2) $errors[] = 'Enter your business or store name.';

    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (:name, :email, :phone, :hash, :role)'
            );
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            $userId = (int) $pdo->lastInsertId();

            if ($role === 'seller') {
                $stmt = $pdo->prepare(
                    'INSERT INTO seller_profiles (user_id, business_name, verification_status) VALUES (:uid, :business, "pending")'
                );
                $stmt->execute(['uid' => $userId, 'business' => $businessName]);

                $stmt = $pdo->prepare(
                    'INSERT INTO stores (seller_user_id, store_name, status) VALUES (:uid, :name, "pending")'
                );
                $stmt->execute(['uid' => $userId, 'name' => $businessName]);
            }

            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            flash('success', $role === 'seller'
                ? 'Your seller account has been created. Your store is pending administrator review.'
                : 'Your Zandoonline account has been created.');
            redirect('dashboard.php');
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') {
                $errors[] = 'An account with that email address or phone number already exists.';
            } else {
                error_log($e->getMessage());
                $errors[] = 'We could not create your account. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create account';
require ROOT_PATH . '/app/views/header.php';
?>
<section class="form-wrap">
    <h1>Create your account</h1>
    <p class="muted">Join customers and sellers on Zandoonline.</p>
    <?php foreach ($errors as $error): ?><div class="alert error"><?= e($error) ?></div><?php endforeach; ?>
    <form method="post" class="form-card">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>Full name<input name="full_name" maxlength="120" required value="<?= old('full_name') ?>"></label>
        <label>Email address<input type="email" name="email" maxlength="190" required value="<?= old('email') ?>"></label>
        <label>Phone number<input type="tel" name="phone" maxlength="30" autocomplete="tel" placeholder="0700 123 456" required value="<?= old('phone') ?>"><small>Use a Ugandan number; you can log in with this or your email.</small></label>
        <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"><small>Use at least 10 characters.</small></label>
        <label>Account type
            <select name="role" id="account-role">
                <option value="customer" <?= (($_POST['role'] ?? '') === 'customer') ? 'selected' : '' ?>>Customer</option>
                <option value="seller" <?= (($_POST['role'] ?? '') === 'seller') ? 'selected' : '' ?>>Seller</option>
            </select>
        </label>
        <label id="business-field" class="hidden">Business / store name
            <input name="business_name" maxlength="150" value="<?= old('business_name') ?>">
        </label>
        <button class="button primary full" type="submit">Create account</button>
    </form>
    <p class="center muted">Already registered? <a href="<?= e($config['base_url']) ?>/login.php">Log in</a></p>
</section>
<script>
const roleSelect = document.getElementById('account-role');
const businessField = document.getElementById('business-field');
function updateBusinessField() {
    const isSeller = roleSelect.value === 'seller';
    businessField.classList.toggle('hidden', !isSeller);
    businessField.querySelector('input').required = isSeller;
}
roleSelect.addEventListener('change', updateBusinessField);
updateBusinessField();
</script>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>
