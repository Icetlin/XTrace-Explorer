<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profiler+ : capture Symfony WebProfilerBundle snapshots linked to a trace file';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profiler_snapshot (
            id SERIAL NOT NULL,
            trace_file_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            base_url VARCHAR(255) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'manual\',
            error_message TEXT DEFAULT NULL,
            request_method VARCHAR(16) DEFAULT NULL,
            request_path VARCHAR(1024) DEFAULT NULL,
            total_queries INT NOT NULL DEFAULT 0,
            total_ms DOUBLE PRECISION NOT NULL DEFAULT 0,
            analysis_json TEXT DEFAULT NULL,
            raw_json TEXT DEFAULT NULL,
            captured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_profiler_snapshot_trace_file FOREIGN KEY (trace_file_id)
                REFERENCES trace_file(id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_profiler_snapshot_trace_file ON profiler_snapshot (trace_file_id)');
        $this->addSql('CREATE INDEX idx_profiler_snapshot_token ON profiler_snapshot (token)');

        $this->addSql('CREATE TABLE profiler_query (
            id SERIAL NOT NULL,
            snapshot_id INT NOT NULL,
            n INT NOT NULL,
            sql TEXT NOT NULL,
            time VARCHAR(32) NOT NULL,
            time_ms DOUBLE PRECISION NOT NULL DEFAULT 0,
            params_json TEXT DEFAULT NULL,
            caller_class VARCHAR(255) DEFAULT NULL,
            caller_method VARCHAR(128) DEFAULT NULL,
            caller_file VARCHAR(1024) DEFAULT NULL,
            caller_host_path VARCHAR(1024) DEFAULT NULL,
            caller_line INT DEFAULT NULL,
            backtrace_json TEXT DEFAULT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_profiler_query_snapshot FOREIGN KEY (snapshot_id)
                REFERENCES profiler_snapshot(id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_profiler_query_snapshot_n ON profiler_query (snapshot_id, n)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE profiler_query');
        $this->addSql('DROP TABLE profiler_snapshot');
    }
}