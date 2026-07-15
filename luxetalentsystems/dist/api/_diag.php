<?php
header('Content-Type: text/plain');
echo "=== SERVER VIEW ===\n";
echo "Cookies received: "; var_export($_COOKIE); echo "\n\n";
echo "SESSION before start: "; var_export($_SESSION ?? []); echo "\n\n";
session_start();
echo "Session ID: " . session_id() . "\n";
echo "Session file: " . session_save_path() . "/sess_" . session_id() . "\n";
echo "File exists: " . (file_exists(session_save_path() . "/sess_" . session_id()) ? "yes" : "no") . "\n";
echo "File contents: \n";
$f = session_save_path() . "/sess_" . session_id();
if (file_exists($f)) echo file_get_contents($f);
echo "\n\n=== HEADERS RECEIVED ===\n";
foreach (getallheaders() as $k => $v) echo "$k: $v\n";
