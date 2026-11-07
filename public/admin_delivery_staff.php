<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_role('admin');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'create') {
        $name = trim(is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '');
        $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
        $phoneInput = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');
$phone = normalize_ugandan_phone($phoneInput);
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$vehiclePlate = trim(is_string($_POST['vehicle_plate'] ?? null) ? $_POST['vehicle_plate'] : '');
$vehicleType = is_string($_POST['vehicle_type'] ?? null) && in_array($_POST['vehicle_type'], ['bodaboda','car'], true) ? $_POST['vehicle_type'] : 'bodaboda';
$uploadDir = ROOT_PATH . '/public/assets/uploads/delivery_docs';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0775, true); }
$photoFields = ['profile_photo','government_id_photo','drivers_license_photo'];
$photoPaths = [];
foreach ($photoFields as $field) {
    if (!empty($_FILES[$field]['tmp_name']) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
        $ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
        $name = $field . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $uploadDir . '/' . $name;
        if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
            $photoPaths[$field] = 'assets/uploads/delivery_docs/' . $name;
        }
    }
}

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $phone === null || strlen($password) < 14) {
            flash('error', 'Enter a valid name, email, Ugandan phone number, and temporary password of at least 14 characters.');
            redirect('admin_delivery_staff.php');
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, phone, vehicle_type, vehicle_plate, profile_photo, government_id_photo, drivers_license_photo, password_hash, role, status) VALUES (:name, :email, :phone, :vehicle_type, :vehicle_plate, :profile_photo, :government_id_photo, :drivers_license_photo, :hash, 'delivery', 'active')");
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'vehicle_type' => $vehicleType,
                'vehicle_plate' => $vehiclePlate !== '' ? $vehiclePlate : null,
                'profile_photo' => $photoPaths['profile_photo'] ?? null,
                'government_id_photo' => $photoPaths['government_id_photo'] ?? null,
                'drivers_license_photo' => $photoPaths['drivers_license_photo'] ?? null,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            flash('success', 'Delivery account created. Share the temporary password with the assigned staff member securely.');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                flash('error', 'That email or phone number is already in use.');
            } else {
                error_log($exception->getMessage());
                flash('error', 'Delivery account could not be created. Please try again.');
            }
        }
        redirect('admin_delivery_staff.php');
    }

    if ($action === 'toggle_status') {
        $deliveryUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if (!$deliveryUserId || !in_array($status, ['active', 'suspended'], true)) {
            flash('error', 'That delivery account update is not valid.');
            redirect('admin_delivery_staff.php');
        }
        $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'delivery'");
        $stmt->execute(['status' => $status, 'id' => $deliveryUserId]);
        flash('success', 'Delivery account access updated.');
        redirect('admin_delivery_staff.php');
    }

    if ($action === 'update') {
    $deliveryUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$deliveryUserId) { flash('error','Invalid account.'); redirect('admin_delivery_staff.php'); }
    $name = trim(is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '');
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $phoneInput = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');
    $phone = normalize_ugandan_phone($phoneInput);
    $vehicleType = is_string($_POST['vehicle_type'] ?? null) && in_array($_POST['vehicle_type'], ['bodaboda','car'], true) ? $_POST['vehicle_type'] : 'bodaboda';
    $vehiclePlate = trim(is_string($_POST['vehicle_plate'] ?? null) ? $_POST['vehicle_plate'] : '');
    $stmt = $pdo->prepare("UPDATE users SET full_name=:name,email=:email,phone=:phone,vehicle_type=:vt,vehicle_plate=:vp WHERE id=:id AND role='delivery'");
    $stmt->execute(['name'=>$name,'email'=>$email,'phone'=>$phone,'vt'=>$vehicleType,'vp'=>$vehiclePlate!==''?$vehiclePlate:null,'id'=>$deliveryUserId]);
    flash('success','Delivery profile updated.');
    redirect('admin_delivery_staff_profile.php?id='.$deliveryUserId);
}
        $deliveryUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$deliveryUserId) {
            flash('error', 'Invalid delivery account.');
            redirect('admin_delivery_staff.php');
        }
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'delivery'");
        $stmt->execute(['id' => $deliveryUserId]);
        flash('success', 'Delivery account deleted.');
        redirect('admin_delivery_staff.php');
    }

if (is_post() && (is_string($_POST['action'] ?? null) ? $_POST['action'] : '') === 'reset_password') {
    verify_csrf();
    $deliveryUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $newPassword = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!$deliveryUserId || strlen($newPassword) < 14) {
        flash('error', 'Enter a new password of at least 14 characters.');
        redirect('admin_delivery_staff.php');
    }
    $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id AND role = 'delivery'");
    $stmt->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $deliveryUserId]);
    flash('success', 'Delivery password reset. Share the new temporary password securely.');
    redirect('admin_delivery_staff.php');
}


$deliveryStaff = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.phone, u.vehicle_type, u.vehicle_plate, u.status, COUNT(o.id) AS active_deliveries
     FROM users u
     LEFT JOIN orders o ON o.delivery_user_id = u.id AND o.order_status NOT IN ('delivered','cancelled')
     WHERE u.role = 'delivery'
     GROUP BY u.id, u.full_name, u.email, u.phone, u.status
     ORDER BY u.full_name"
)->fetchAll();
$pageTitle = 'Delivery team';
require ROOT_PATH . '/app/views/header.php';
require ROOT_PATH . '/app/views/admin_nav.php';
?>
<section class="admin-console">
    <div class="admin-page-heading">
        <div><p class="market-kicker">PRIVATE DELIVERY ACCESS</p><h1>Delivery team</h1><p>Create staff accounts and control access to assigned deliveries.</p></div>
    </div>
    <section class="admin-panel delivery-staff-panel">
        <div class="admin-panel-heading"><h2>Delivery accounts</h2><span><?= count($deliveryStaff) ?> staff</span></div>
        <?php if (!$deliveryStaff): ?>
            <p class="muted">No delivery accounts yet.</p>
        <?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table delivery-staff-table">
                <thead><tr><th>Name</th><th>Phone</th><th>Vehicle</th><th>Active deliveries</th><th>Access</th><th>Password</th><th></th></tr></thead>
                <tbody><?php foreach ($deliveryStaff as $staff): ?>
                    <tr>
                        <td><strong><?= e($staff['full_name']) ?></strong><small><?= e($staff['email']) ?></small><br><a href="<?= e($config['base_url']) ?>/admin_delivery_staff_profile.php?id=<?= (int)$staff['id'] ?>">View profile</a></td>
                        <td><?= e($staff['phone']) ?></td>
                        <td><?= e(ucfirst($staff['vehicle_type'] ?? '')) ?> <?= e($staff['vehicle_plate'] ?? '') ?></td>
                        <td><?= (int) $staff['active_deliveries'] ?></td>
                        <td><span class="status <?= $staff['status'] === 'active' ? 'active' : 'pending' ?>"><?= e(ucfirst($staff['status'])) ?></span></td>
                        <td><form method="post" action="<?= e($config['base_url']) ?>/admin_delivery_staff.php" class="delivery-reset-form">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= (int) $staff['id'] ?>">
                            <input type="password" name="password" minlength="14" placeholder="New temp password" required>
                            <button class="text-button" type="submit">Reset</button>
                        </form></td>
                        <td><form method="post" action="<?= e($config['base_url']) ?>/admin_delivery_staff.php">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="user_id" value="<?= (int) $staff['id'] ?>">
                            <input type="hidden" name="status" value="<?= $staff['status'] === 'active' ? 'suspended' : 'active' ?>">
                            <button class="text-button" type="submit"><?= $staff['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button>
                        </form>
                        <form method="post" action="<?= e($config['base_url']) ?>/admin_delivery_staff.php" onsubmit="return confirm('Delete this delivery account?');">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= (int) $staff['id'] ?>">
                            <button class="text-button" type="submit">Delete</button>
                        </form></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <section class="admin-panel add-delivery-panel">
        <div class="admin-panel-heading"><h2>Create delivery account</h2><span>Delivery staff cannot self-register.</span></div>
        <form class="add-delivery-form" method="post" enctype="multipart/form-data" action="<?= e($config['base_url']) ?>/admin_delivery_staff.php">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Full name<input name="full_name" maxlength="120" required></label>
            <label>Email address<input type="email" name="email" maxlength="190" required></label>
            <label>Phone number<input type="tel" name="phone" maxlength="30" placeholder="0770 123 456" required></label>
<label>Vehicle type<select name="vehicle_type" required><option value="bodaboda">Bodaboda</option><option value="car">Car</option></select></label>
<label>Vehicle plate number<input name="vehicle_plate" maxlength="30"></label>
<label>Profile photo<input type="file" name="profile_photo" accept="image/*"></label>
<label>Government ID photo<input type="file" name="government_id_photo" accept="image/*"></label>
<label>Driver's license photo<input type="file" name="drivers_license_photo" accept="image/*"></label>
            <label>Temporary password<input type="password" name="password" id="delivery-temp-password" minlength="14" autocomplete="new-password" required><small>At least 14 characters.</small></label>
<button type="button" class="text-button" onclick="const f=document.getElementById('delivery-temp-password'); f.type = f.type === 'password' ? 'text' : 'password'; this.textContent = f.type === 'password' ? 'Show password' : 'Hide password';">Show password</button>
            <button class="button primary" type="submit">Create account</button>
        </form>
    </section>
</section>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>
