<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE endpoint_timing (
            id SERIAL NOT NULL,
            endpoint_url VARCHAR(255) NOT NULL,
            endpoint_method VARCHAR(10) NOT NULL,
            trace_name VARCHAR(255) DEFAULT NULL,
            duration_ms INT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX idx_endpoint_timing_trace_name ON endpoint_timing (trace_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE endpoint_timing');
    }
}
