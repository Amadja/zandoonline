<?php
require 'C:/XAMPP2/htdocs/zandoonline/app/bootstrap.php';
$pdo = db();
$pdo->exec("UPDATE users SET profile_photo=REPLACE(profile_photo,'storage/delivery_docs/','assets/uploads/delivery_docs/'), government_id_photo=REPLACE(government_id_photo,'storage/delivery_docs/','assets/uploads/delivery_docs/'), drivers_license_photo=REPLACE(drivers_license_photo,'storage/delivery_docs/','assets/uploads/delivery_docs/')");
echo "done\n";
