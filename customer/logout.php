<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';

customer_logout();
redirect(base_url('index.php'));
