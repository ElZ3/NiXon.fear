<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Controllers/AuthController.php';

use App\Controllers\AuthController;

$auth = new AuthController();
$auth->bootSession();

header('Location: login.php');
exit;
