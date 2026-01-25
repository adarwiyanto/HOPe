<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/functions.php';
logout();
redirect(base_url('login.php'));
