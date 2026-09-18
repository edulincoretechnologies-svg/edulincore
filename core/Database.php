<?php

declare(strict_types=1);

final class Database
{
    private static ?self $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $config = self::loadConfig();

        $host = (string)($config['host'] ?? 'localhost');
        $port = (int)($config['port'] ?? 3306);
        $name = (string)($config['dbname'] ?? 'iwnd_220_edulincore_db');
        $user = (string)($config['username'] ?? 'mulundumina');
        $pass = (string)($config['password'] ?? '$ithabi$ile2020');
        $charset = (string)($config['charset'] ?? 'utf8mb4');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $name,
            $charset
        );

        try {
            $this->connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $exception) {
            error_log('Database connection failed: ' . $exception->getMessage());
            throw new RuntimeException(
                'Database connection failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    private static function loadConfig(): array
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : '/var/www/vhosts/iwnd-220.imbra/site1';
        $configPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

        if (!is_file($configPath)) {
            return [];
        }

        $loadedConfig = require $configPath;
        return is_array($loadedConfig) ? $loadedConfig : [];
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function getConnection(): PDO
    {
        return self::getInstance()->connection;
    }

    private function __clone(): void
    {
    }

    public function __wakeup(): void
    {
        throw new LogicException('Cannot unserialize Database.');
    }
}
