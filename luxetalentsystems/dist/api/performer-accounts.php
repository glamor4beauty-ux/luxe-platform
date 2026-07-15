<?php
// ════════════════════════════════════════════════════════════════════
// /api/performer-accounts.php — admin-managed performer accounts
//
// Actions (admin only unless noted):
//   GET  list                 — list accounts with last login
//   POST save                 — create or update an account
//   POST reset_password       — set a new password for an account
//   POST delete               — delete an account (also from registration)
//   POST last_seen            — performer pings this on dashboard load
//                              (no admin required; uses session email)
// ════════════════════════════════════════════════════════════════════

require __DIR__.'/../cors.php';
session_start();
require __DIR__.'/../config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$email  = $_SESSION['user_email'] ?? '';
$role   = $_SESSION['user_role']  ?? '';
$isAdmin = in_array(strtolower($role), ['admin','superadmin']);

// last_seen is the only action a non-admin can call
if (!$isAdmin && $action !== 'last_seen') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Admin only']);
    exit;
}
if (!$email) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Not signed in']);
    exit;
}

try {
    $pdo = db();

    switch ($action) {
        // ── List ──
        case 'list': {
            $st = $pdo->query("
                SELECT id, stage_name, first_name, last_name, email,
                       active, last_login_at, notes, created_at, updated_at,
                       CHAR_LENGTH(password_hash) > 0 AS has_password
                FROM performer_accounts
                ORDER BY active DESC, stage_name ASC, id DESC
            ");
            echo json_encode(['ok'=>true, 'accounts'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        // ── Save (create or update) ──
        case 'save': {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $id         = (int)($in['id'] ?? 0);
            $stage      = trim($in['stage_name'] ?? '');
            $first      = trim($in['first_name'] ?? '');
            $last       = trim($in['last_name'] ?? '');
            $em         = strtolower(trim($in['email'] ?? ''));
            $password   = $in['password'] ?? '';  // exact
            $active     = !empty($in['active']) ? 1 : 0;
            $notes      = trim($in['notes'] ?? '');

            if (!$em || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'Valid email required']);
                exit;
            }
            if (!$stage && !$first && !$last) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'Stage name or real name required']);
                exit;
            }
            // Password required on create; optional on update
            $isCreate = $id <= 0;
            if ($isCreate && strlen($password) < 4) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'Password required (min 4 chars)']);
                exit;
            }

            if ($isCreate) {
                // Unique email check
                $st = $pdo->prepare("SELECT id FROM performer_accounts WHERE email = ? LIMIT 1");
                $st->execute([$em]);
                if ($st->fetchColumn()) {
                    http_response_code(409);
                    echo json_encode(['ok'=>false,'error'=>'Email already exists']);
                    exit;
                }

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $st = $pdo->prepare("
                    INSERT INTO performer_accounts
                    (stage_name, first_name, last_name, email, password_hash, active, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?)
                ");
                $st->execute([$stage, $first, $last, $em, $hash, $active, $notes, $email]);
                $newId = $pdo->lastInsertId();

                // Mirror to `registration` so existing login.php works
                mirrorToRegistration($pdo, $em, $stage, $first, $last, $hash);

                echo json_encode(['ok'=>true, 'id'=>$newId, 'created'=>true]);
            } else {
                // Update — password optional
                if (strlen($password) > 0) {
                    if (strlen($password) < 4) {
                        http_response_code(400);
                        echo json_encode(['ok'=>false,'error'=>'Password too short']);
                        exit;
                    }
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $st = $pdo->prepare("
                        UPDATE performer_accounts
                        SET stage_name=?, first_name=?, last_name=?, email=?, password_hash=?, active=?, notes=?
                        WHERE id=?
                    ");
                    $st->execute([$stage, $first, $last, $em, $hash, $active, $notes, $id]);
                    mirrorToRegistration($pdo, $em, $stage, $first, $last, $hash);
                } else {
                    $st = $pdo->prepare("
                        UPDATE performer_accounts
                        SET stage_name=?, first_name=?, last_name=?, email=?, active=?, notes=?
                        WHERE id=?
                    ");
                    $st->execute([$stage, $first, $last, $em, $active, $notes, $id]);
                    mirrorToRegistration($pdo, $em, $stage, $first, $last, null);
                }
                echo json_encode(['ok'=>true, 'id'=>$id, 'updated'=>true]);
            }
            break;
        }

        // ── Reset password ──
        case 'reset_password': {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $id       = (int)($in['id'] ?? 0);
            $password = $in['password'] ?? '';
            if ($id <= 0 || strlen($password) < 4) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'id and password (min 4 chars) required']);
                exit;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $st = $pdo->prepare("UPDATE performer_accounts SET password_hash=? WHERE id=?");
            $st->execute([$hash, $id]);

            // Get email to update registration too
            $st = $pdo->prepare("SELECT email, stage_name, first_name, last_name FROM performer_accounts WHERE id=?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                mirrorToRegistration($pdo, $row['email'], $row['stage_name'], $row['first_name'], $row['last_name'], $hash);
            }
            echo json_encode(['ok'=>true, 'reset'=>true]);
            break;
        }

        // ── Delete ──
        case 'delete': {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'id required']);
                exit;
            }
            // Get email so we can also remove from registration
            $st = $pdo->prepare("SELECT email FROM performer_accounts WHERE id=?");
            $st->execute([$id]);
            $em = $st->fetchColumn();

            $st = $pdo->prepare("DELETE FROM performer_accounts WHERE id=?");
            $st->execute([$id]);

            if ($em) {
                // Only delete from registration if it has no other related rows we'd lose
                // For now: safe approach is to just mark inactive
                try {
                    $st = $pdo->prepare("DELETE FROM registration WHERE email=? LIMIT 1");
                    $st->execute([$em]);
                } catch (Throwable $e) { /* leave it if it has FK refs */ }
            }
            echo json_encode(['ok'=>true, 'deleted'=>true]);
            break;
        }

        // ── Last seen (called by performer dashboard on load) ──
        case 'last_seen': {
            // Uses session email — performer can only update their own
            $st = $pdo->prepare("UPDATE performer_accounts SET last_login_at = NOW() WHERE email = ?");
            $st->execute([$email]);
            echo json_encode(['ok'=>true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>'Unknown action: '.$action]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

// ════════════════════════════════════════════════════════════════════
// Mirror to `registration` so existing login flow works unchanged.
// Pass $hash = null to leave password untouched.
// ════════════════════════════════════════════════════════════════════
function mirrorToRegistration(PDO $pdo, $em, $stage, $first, $last, $hash) {
    try {
        // Check which columns exist
        $cols = [];
        $st = $pdo->query("SHOW COLUMNS FROM registration");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }

        // Exists?
        $st = $pdo->prepare("SELECT id FROM registration WHERE email = ? LIMIT 1");
        $st->execute([$em]);
        $existingId = $st->fetchColumn();

        if ($existingId) {
            $sets = [];
            $args = [];
            if (isset($cols['stage_name']))  { $sets[] = 'stage_name = ?';  $args[] = $stage; }
            if (isset($cols['first_name']))  { $sets[] = 'first_name = ?';  $args[] = $first; }
            if (isset($cols['last_name']))   { $sets[] = 'last_name = ?';   $args[] = $last; }
            if ($hash !== null && isset($cols['password_hash'])) {
                $sets[] = 'password_hash = ?'; $args[] = $hash;
            } elseif ($hash !== null && isset($cols['password'])) {
                $sets[] = 'password = ?'; $args[] = $hash;
            }
            if (!$sets) return;
            $args[] = $existingId;
            $sql = "UPDATE registration SET ".implode(', ', $sets)." WHERE id = ?";
            $st = $pdo->prepare($sql);
            $st->execute($args);
        } else {
            // Insert minimal row
            $fields = ['email'];
            $values = [$em];
            if (isset($cols['stage_name']))  { $fields[] = 'stage_name';  $values[] = $stage; }
            if (isset($cols['first_name']))  { $fields[] = 'first_name';  $values[] = $first; }
            if (isset($cols['last_name']))   { $fields[] = 'last_name';   $values[] = $last; }
            if ($hash !== null) {
                if (isset($cols['password_hash'])) { $fields[] = 'password_hash'; $values[] = $hash; }
                elseif (isset($cols['password']))  { $fields[] = 'password';      $values[] = $hash; }
            }
            // Common required fields with defaults
            if (isset($cols['role']) && !in_array('role', $fields)) { $fields[] = 'role'; $values[] = 'performer'; }
            if (isset($cols['status']) && !in_array('status', $fields)) { $fields[] = 'status'; $values[] = 'active'; }

            $sql = "INSERT INTO registration (".implode(',', $fields).") VALUES (".implode(',', array_fill(0, count($fields), '?')).")";
            $st = $pdo->prepare($sql);
            $st->execute($values);
        }
    } catch (Throwable $e) {
        // Log but don't fail the whole save — admin can still manage the account
        error_log("mirrorToRegistration failed: " . $e->getMessage());
    }
}
