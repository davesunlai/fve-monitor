<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';
use FveMonitor\Lib\Auth;

Auth::logout();
header('Location: login.php');
