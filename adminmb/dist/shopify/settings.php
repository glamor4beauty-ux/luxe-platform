<?php
/* ═══════════════════════════════════════════════════════════════════════════
   settings.php — accounts. Superadmin only.

   Passwords are stored hashed and cannot be read back, so this offers to set a
   new one rather than pretending to show the old.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/shell.php';
require_once __DIR__ . '/authz.php';

$me = auth_require_super();

$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do    = (string)($_POST['do'] ?? '');
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $users = users_load();

    if ($do === 'add') {
        $pass = (string)($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? ROLE_ADMIN) === ROLE_SUPER ? ROLE_SUPER : ROLE_ADMIN;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'That does not look like an email address.';
        } elseif ($p = password_problem($pass)) {
            $err = $p;
        } else {
            foreach ($users as $u) {
                if (strcasecmp($u['email'], $email) === 0) { $err = 'That email already has an account.'; break; }
            }
            if (!$err) {
                $users[] = ['email' => $email, 'hash' => password_hash($pass, PASSWORD_DEFAULT),
                            'role' => $role, 'created' => date('c')];
                $msg = users_save($users) ? $email . ' added.' : '';
                if (!$msg) $err = 'Could not write the account file.';
            }
        }

    } elseif ($do === 'password') {
        $pass = (string)($_POST['password'] ?? '');
        if ($p = password_problem($pass)) {
            $err = $p;
        } else {
            $hit = false;
            foreach ($users as &$u) {
                if (strcasecmp($u['email'], $email) === 0) {
                    $u['hash'] = password_hash($pass, PASSWORD_DEFAULT);
                    $hit = true;
                }
            }
            unset($u);
            if (!$hit) $err = 'No such account.';
            else { $msg = users_save($users) ? 'Password changed for ' . $email . '.' : ''; }
            if (!$msg && !$err) $err = 'Could not write the account file.';
        }

    } elseif ($do === 'role') {
        $role = ($_POST['role'] ?? ROLE_ADMIN) === ROLE_SUPER ? ROLE_SUPER : ROLE_ADMIN;

        /* the last superadmin cannot demote themselves out of the only account
           that can add anyone back */
        $supers = 0;
        foreach ($users as $u) if (($u['role'] ?? '') === ROLE_SUPER) $supers++;
        $target = null;
        foreach ($users as $u) if (strcasecmp($u['email'], $email) === 0) $target = $u;

        if (!$target) {
            $err = 'No such account.';
        } elseif ($target['role'] === ROLE_SUPER && $role !== ROLE_SUPER && $supers <= 1) {
            $err = 'That is the only superadmin. Promote somebody else first.';
        } else {
            foreach ($users as &$u) {
                if (strcasecmp($u['email'], $email) === 0) $u['role'] = $role;
            }
            unset($u);
            $msg = users_save($users) ? 'Role changed for ' . $email . '.' : '';
            if (!$msg) $err = 'Could not write the account file.';
        }

    } elseif ($do === 'delete') {
        if (strcasecmp($email, $me['email']) === 0) {
            $err = 'You cannot delete your own account.';
        } else {
            $supers = 0;
            foreach ($users as $u) if (($u['role'] ?? '') === ROLE_SUPER) $supers++;
            $target = null;
            foreach ($users as $u) if (strcasecmp($u['email'], $email) === 0) $target = $u;

            if ($target && ($target['role'] ?? '') === ROLE_SUPER && $supers <= 1) {
                $err = 'That is the only superadmin.';
            } else {
                $users = array_values(array_filter($users,
                    fn($u) => strcasecmp($u['email'], $email) !== 0));
                $msg = users_save($users) ? $email . ' removed.' : '';
                if (!$msg) $err = 'Could not write the account file.';
            }
        }
    }
}

$users = users_load();
page_open('Settings', 'settings');
?>

<header class="top">
  <div>
    <div class="app"><?= e(APP_NAME) ?><?= page_gear() ?><?php if (!can_write()): ?><span class="readonly">read only</span><?php endif; ?></div>
    <h1>Settings</h1>
  </div>
  <div class="right"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></div>
</header>

<div class="wrap" style="padding-top:14px">

  <?php if ($msg): ?><div class="note ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="problem" style="margin-bottom:14px"><p style="margin:0"><?= e($err) ?></p></div><?php endif; ?>

  <div class="grp">
    <h3>Who has an account</h3>
    <div class="list">
      <?php foreach ($users as $u):
        $isMe = strcasecmp($u['email'], $me['email']) === 0;
        $super = ($u['role'] ?? '') === ROLE_SUPER;
      ?>
        <div class="urow">
          <div class="uinfo">
            <b><?= e($u['email']) ?><?= $isMe ? ' <span class="you">you</span>' : '' ?></b>
            <span class="pill <?= $super ? 'ok' : '' ?>">
              <?= $super ? 'superadmin · reads and writes' : 'admin · read only' ?>
            </span>
          </div>
          <div class="uacts">
            <button class="act sm" type="button"
                    onclick="pwForm('<?= e($u['email']) ?>')">Password</button>

            <form method="post" style="display:inline">
              <input type="hidden" name="do" value="role">
              <input type="hidden" name="email" value="<?= e($u['email']) ?>">
              <input type="hidden" name="role" value="<?= $super ? ROLE_ADMIN : ROLE_SUPER ?>">
              <button class="act sm" type="submit"
                      onclick="return confirm('<?= $super
                        ? 'Make ' . e($u['email']) . ' read only?'
                        : 'Give ' . e($u['email']) . ' full write access?' ?>')">
                <?= $super ? 'Make read only' : 'Give write' ?>
              </button>
            </form>

            <?php if (!$isMe): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="email" value="<?= e($u['email']) ?>">
                <button class="act sm danger" type="submit"
                        onclick="return confirm('Remove <?= e($u['email']) ?>? They lose access straight away.')">
                  Remove
                </button>
              </form>
            <?php endif; ?>
          </div>

          <form method="post" class="pwform" id="pw-<?= e(md5($u['email'])) ?>" style="display:none">
            <input type="hidden" name="do" value="password">
            <input type="hidden" name="email" value="<?= e($u['email']) ?>">
            <label class="fl">New password
              <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <div class="form-b">
              <button class="act ghost" type="button"
                      onclick="pwForm('<?= e($u['email']) ?>')">Cancel</button>
              <button class="act go" type="submit">Set it</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="note">
      Passwords are stored hashed and cannot be read back — not by anyone, including
      you. If somebody forgets theirs, set a new one here.
    </div>
  </div>

  <div class="grp">
    <h3>Add someone</h3>
    <form method="post" class="addform">
      <input type="hidden" name="do" value="add">
      <label class="fl">Email
        <input type="email" name="email" required autocomplete="off">
      </label>
      <label class="fl">Password
        <input type="password" name="password" required autocomplete="new-password">
      </label>
      <label class="fl">Role
        <select name="role">
          <option value="<?= ROLE_ADMIN ?>">Admin — read only</option>
          <option value="<?= ROLE_SUPER ?>">Superadmin — reads and writes</option>
        </select>
      </label>
      <div class="form-b"><button class="act go" type="submit">Add</button></div>
    </form>
    <div class="note">
      At least 10 characters, with a number or symbol. Read-only is enforced on the
      server, not just hidden — an admin cannot write by typing a URL.
    </div>
  </div>

  <div class="grp">
    <h3>This session</h3>
    <div class="note" style="margin-top:0">
      Signed in as <b><?= e($me['email']) ?></b>.
    </div>
    <a class="more" href="logout.php" style="margin-top:10px">Sign out</a>
  </div>

</div>

<style>
.urow{padding:13px 14px;border-bottom:1px solid var(--line)}
.urow:last-child{border-bottom:none}
.uinfo b{display:block;font-size:14px;font-weight:600;margin-bottom:5px;word-break:break-all}
.uinfo .you{font-size:10px;color:var(--gold);font-weight:400;margin-left:5px}
.uacts{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}
.act.danger:hover{border-color:var(--bad);color:var(--bad)}
.pwform{margin-top:12px;padding-top:12px;border-top:1px solid var(--line)}
.addform{border:1px solid var(--line);border-radius:12px;padding:16px}
.note{background:rgba(216,169,75,.05);border-left:2px solid rgba(216,169,75,.4);
  border-radius:0 6px 6px 0;padding:10px 13px;margin-top:12px;font-size:12px;
  color:var(--mute);line-height:1.6}
.note b{color:var(--gold)}
.note.ok{background:rgba(78,201,122,.08);border-left-color:var(--ok);color:#a8e6bd}
.grp h3{font-size:10.5px}
</style>

<script>
function pwForm(email){
  var id='pw-'+md5(email);
  var f=document.getElementById(id);
  if(!f) return;
  f.style.display = f.style.display==='none' ? 'block' : 'none';
  if(f.style.display==='block') f.querySelector('input[type=password]').focus();
}
/* the ids are md5 of the email, matched here so no address ends up in the DOM
   as an attribute value */
function md5(s){ return MD5MAP[s] || ''; }
var MD5MAP = <?= json_encode(array_combine(
    array_map(fn($u) => $u['email'], $users),
    array_map(fn($u) => md5($u['email']), $users)
) ?: []) ?>;
</script>

<?php page_close('settings'); ?>
