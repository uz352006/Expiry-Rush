<?php
if (session_status() === PHP_SESSION_NONE) session_start();
session_destroy();
header('Location: /expiry_rush/index.php');
exit;
