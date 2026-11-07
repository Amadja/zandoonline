<?php
require 'C:/XAMPP2/htdocs/zandoonline/app/bootstrap.php';
$pdo = db();
$sql = file_get_contents('C:/XAMPP2/htdocs/zandoonline/database/add_delivery_documents.sql');
$pdo->exec($sql);
echo "done\n";
