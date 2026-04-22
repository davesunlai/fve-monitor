<?php
/**
 * Admin bootstrap — session-based autentifikace.
 *
 * Uživatelé jsou v tabulce `users`, login přes /admin/login.php.
 * Při nepřihlášení redirect na login s návratovou URL.
 *
 * Stará HTTP Basic Auth logika je zazálohovaná v _auth.php.bak-basic.
 */
declare(strict_types=1);
$config = require __DIR__ . '/../../bootstrap.php';

\FveMonitor\Lib\Auth::requireLogin('/admin/login.php');

// Autentizováno. Pro pohodlí dáme aktuálního uživatele do $currentUser.
$currentUser = \FveMonitor\Lib\Auth::currentUser();
