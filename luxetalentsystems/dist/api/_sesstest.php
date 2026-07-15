<?php
session_start();
header('Content-Type: application/json');
echo json_encode([
    'session_id'   => session_id(),
    'session_name' => session_name(),
    'cookies_seen' => $_COOKIE,
    'session_data' => $_SESSION,
    'save_path'    => session_save_path(),
]);
