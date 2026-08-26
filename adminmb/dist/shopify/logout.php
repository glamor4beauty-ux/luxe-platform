<?php
require __DIR__ . '/authz.php';
auth_logout();
header('Location: login.php');
exit;
