<?php
/* Luxe platform shared auth — one login across *.divafans.club */
session_set_cookie_params([
  'lifetime'=>0, 'path'=>'/', 'domain'=>'.divafans.club',
  'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax',
]);
session_name('LUXESESS');
session_start();

const LUXE_USERS = '/opt/owncast/private/users.json';   // shared platform user store

function luxe_users(){ return is_file(LUXE_USERS) ? (json_decode(file_get_contents(LUXE_USERS), true) ?: []) : []; }
function jout($a){ header('Content-Type: application/json'); echo json_encode($a); exit; }
function current_user(){ return $_SESSION['luxe_user'] ?? null; }
function current_role(){ return $_SESSION['luxe_role'] ?? null; }
function require_login(){ if (empty($_SESSION['luxe_user'])) { http_response_code(401); jout(['ok'=>false,'error'=>'auth required']); } }
function require_admin(){ require_login(); if (($_SESSION['luxe_role'] ?? '') !== 'admin') { http_response_code(403); jout(['ok'=>false,'error'=>'admin only']); } }
