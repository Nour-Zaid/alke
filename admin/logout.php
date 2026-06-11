<?php
session_start();
$_SESSION = [];
session_destroy();
header('Location: /alke/admin/login.php');
exit;
