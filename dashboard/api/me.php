<?php require __DIR__.'/_auth.php';
jout(['ok'=>!empty($_SESSION['luxe_user']), 'user'=>current_user(), 'role'=>current_role()]);
