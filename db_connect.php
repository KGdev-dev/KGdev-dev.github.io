<?php
// Live Server Production Configuration
$host = 'localhost'; // Keep as localhost — Namecheap keeps DBs on the same server container
$db   = 'kchaucbd_exchange'; // Full database name
$user = 'kchaucbd_KASI'; // Full database user
$pass = '9Z=Z&94IsOw{t1k%'; // Secure generated password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int) $e->getCode());
}
