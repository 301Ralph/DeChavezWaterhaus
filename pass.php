<?php
$password = "Admin123!"; // Change this
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "Password Hash: " . $hash;
?>