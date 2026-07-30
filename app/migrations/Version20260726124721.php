<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726124721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE job_logs (id UUID NOT NULL, level VARCHAR(20) NOT NULL, message TEXT NOT NULL, context JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, job_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7743B01DBE04EA9 ON job_logs (job_id)');
        $this->addSql('CREATE INDEX idx_job_logs_created_at ON job_logs (created_at)');
        $this->addSql('CREATE TABLE jobs (id UUID NOT NULL, type VARCHAR(50) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, retry_count INT DEFAULT 0 NOT NULL, priority SMALLINT DEFAULT 0 NOT NULL, processed_by VARCHAR(100) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE job_logs ADD CONSTRAINT FK_7743B01DBE04EA9 FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE job_logs DROP CONSTRAINT FK_7743B01DBE04EA9');
        $this->addSql('DROP TABLE job_logs');
        $this->addSql('DROP TABLE jobs');
    }
}
