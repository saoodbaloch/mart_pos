<?php
require __DIR__ . '/inc/bootstrap.php';
redirect(current_user() ? 'billing.php' : 'login.php');
