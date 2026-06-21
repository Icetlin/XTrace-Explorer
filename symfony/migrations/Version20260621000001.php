<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generic app settings store (key-value) — profiler_enabled toggle lives here';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_setting (
            key VARCHAR(64) NOT NULL,
            value TEXT NOT NULL DEFAULT \'\',
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(key)
        )');

        // Default the Profiler+ toggle to OFF. Users opt in via Settings → Profiler+.
        $this->addSql("INSERT INTO app_setting (key, value, updated_at) VALUES ('profiler_enabled', '0', NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
    }
}
