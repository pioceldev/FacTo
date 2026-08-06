<?php

declare(strict_types=1);

namespace FacTo\Tests;

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testConfigReturnsArray(): void
    {
        // config.php est gitignoré : on retombe sur le fichier d'exemple s'il manque.
        $configFile = __DIR__ . '/../backend/config.php';
        if (!file_exists($configFile)) {
            $configFile = __DIR__ . '/../backend/config.example.php';
        }
        $config = require $configFile;
        $this->assertIsArray($config);
        $this->assertArrayHasKey('db_host', $config);
        $this->assertArrayHasKey('db_name', $config);
        $this->assertArrayHasKey('db_user', $config);
        $this->assertArrayHasKey('db_pass', $config);
    }

    public function testDatabaseConnectionReturnsPDO(): void
    {
        $pdo = \getPDO();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testUsersTableExists(): void
    {
        $pdo = \getPDO();
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $this->assertEquals(1, $stmt->rowCount());
    }

    public function testPlansTableHasThreeDefaultPlans(): void
    {
        $pdo = \getPDO();
        $stmt = $pdo->query("SELECT COUNT(*) FROM plans");
        $this->assertEquals(3, (int) $stmt->fetchColumn());
    }
}