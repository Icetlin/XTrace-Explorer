<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trace_file (
            id SERIAL NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            progress INT NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE TABLE annotation (
            id SERIAL NOT NULL,
            trace_file_id INT NOT NULL,
            line_no INT NOT NULL,
            text TEXT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_annotation_trace_file FOREIGN KEY (trace_file_id)
                REFERENCES trace_file(id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_annotation_trace_file ON annotation (trace_file_id)');

        // Messenger transport table (doctrine transport needs this)
        $this->addSql('CREATE TABLE IF NOT EXISTS messenger_messages (
            id BIGSERIAL NOT NULL,
            body TEXT NOT NULL,
            headers TEXT NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_messenger_queue ON messenger_messages (queue_name, available_at, delivered_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE annotation');
        $this->addSql('DROP TABLE trace_file');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
