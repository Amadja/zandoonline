<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_role('admin');

$pdo = db();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) { flash('error', 'Invalid staff.'); redirect('admin_delivery_staff.php'); }
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'delivery'");
$stmt->execute(['id' => $id]);
$staff = $stmt->fetch();
if (!$staff) { flash('error', 'Staff not found.'); redirect('admin_delivery_staff.php'); }

$pageTitle = 'Delivery staff profile';
require ROOT_PATH . '/app/views/header.php';
require ROOT_PATH . '/app/views/admin_nav.php';
?>
<style>
.staff-profile{max-width:900px;margin:0 auto}
.staff-header{display:flex;align-items:center;gap:1.25rem;margin-bottom:1.5rem}
.staff-avatar{width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid #e5e7eb;background:#f3f4f6}
.staff-meta h1{margin:0;font-size:1.5rem}
.staff-meta p{margin:.25rem 0;color:#6b7280}
.staff-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}
.staff-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.staff-card h3{margin:0 0 .75rem;font-size:.95rem;color:#374151}
.staff-card img{width:100%;height:180px;object-fit:cover;border-radius:8px;background:#f3f4f6}
.staff-card .none{display:flex;align-items:center;justify-content:center;height:180px;color:#9ca3af;border:1px dashed #d1d5db;border-radius:8px}
.staff-info{display:grid;grid-template-columns:1fr 1fr;gap:.5rem 1.5rem;margin-bottom:1.5rem}
.staff-info div{padding:.5rem 0;border-bottom:1px solid #f3f4f6}
.staff-info strong{display:block;font-size:.75rem;text-transform:uppercase;color:#9ca3af}
</style>
<section class="admin-console staff-profile">
  <div class="staff-header">
    <?php if (!empty($staff['profile_photo'])): ?>
      <img class="staff-avatar" src="<?= e($config['base_url']) ?>/<?= e($staff['profile_photo']) ?>" alt="Profile">
    <?php else: ?>
      <div class="staff-avatar"></div>
    <?php endif; ?>
    <div class="staff-meta">
      <h1><?= e($staff['full_name']) ?></h1>
      <p><?= e($staff['email']) ?> · <?= e($staff['phone']) ?></p>
      <p><span class="status <?= $staff['status'] === 'active' ? 'active' : 'pending' ?>"><?= e(ucfirst($staff['status'])) ?></span></p>
    </div>
  </div>
  <div class="staff-info">
    <div><strong>Vehicle type</strong><?= e(ucfirst($staff['vehicle_type'] ?? '—')) ?></div>
    <div><strong>Vehicle plate</strong><?= e($staff['vehicle_plate'] ?? '—') ?></div>
  </div>
  <div class="staff-grid">
    <?php foreach (['profile_photo'=>'Profile photo','government_id_photo'=>'Government ID','drivers_license_photo'=>'Driver license'] as $col=>$label): ?>
      <div class="staff-card">
        <h3><?= e($label) ?></h3>
        <?php if (!empty($staff[$col])): ?>
          <a href="<?= e($config['base_url']) ?>/<?= e($staff[$col]) ?>" target="_blank"><img src="<?= e($config['base_url']) ?>/<?= e($staff[$col]) ?>" alt="<?= e($label) ?>"></a>
        <?php else: ?>
          <div class="none">No image uploaded</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post" action="<?= e($config['base_url']) ?>/admin_delivery_staff.php" style="margin-top:1.5rem">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="user_id" value="<?= (int)$staff['id'] ?>">
    <label>Full name<input name="full_name" value="<?= e($staff['full_name']) ?>" required></label>
    <label>Email<input type="email" name="email" value="<?= e($staff['email']) ?>" required></label>
    <label>Phone<input type="tel" name="phone" value="<?= e($staff['phone']) ?>" required></label>
    <label>Vehicle type<select name="vehicle_type"><option value="bodaboda" <?= ($staff['vehicle_type']??'')==='bodaboda'?'selected':'' ?>>Bodaboda</option><option value="car" <?= ($staff['vehicle_type']??'')==='car'?'selected':'' ?>>Car</option></select></label>
    <label>Vehicle plate<input name="vehicle_plate" value="<?= e($staff['vehicle_plate'] ?? '') ?>"></label>
    <button class="button primary" type="submit">Save changes</button>
  </form>
</section>
<?php require ROOT_PATH . '/app/views/footer.php'; ?>
