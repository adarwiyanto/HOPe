<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/functions.php';
require_admin();
logout();
redirect(base_url('index.php'));
