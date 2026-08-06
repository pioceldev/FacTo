<?php

declare(strict_types=1);

namespace FacTo\Tests;

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testConfigReturnsArray(): void
    {
        $config = require __DIR__ . '/../backend/config.php';
        $this->assertIsArray($config);
        $this->assertArrayHasKey('db_host', $config);
        $this->assertArrayHasKey('db_name', $config);
        $this->assertArrayHasKey('db_user', $config);
        $this->assertArrayHasKey('db_pass', $config);
    }

    public function testDatabaseConnectionReturnsPDO(): void
    {
        $pdo = \FacTo\getPDO();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testUsersTableExists(): void
    {
        $pdo = \FacTo\getPDO();
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $this->assertEquals(1, $stmt->rowCount());
    }

    public function testPlansTableHasThreeDefaultPlans(): void
    {
        $pdo = \FacTo\getPDO();
        $stmt = $pdo->query("SELECT COUNT(*) FROM plans");
        $this->assertEquals(3, (int) $stmt->fetchColumn());
    }
}