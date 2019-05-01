<?php

declare(strict_types=1);

namespace Libero\ContentStore\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20190430074157 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Create items table.';
    }

    public function up(Schema $schema) : void
    {
        $this->addSql('CREATE TABLE items_items (
            sequence SERIAL NOT NULL, 
            id VARCHAR(255) NOT NULL, 
            current_version INT NOT NULL, 
            PRIMARY KEY(sequence)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E5C223F6BF396750 ON items_items (id)');
        $this->addSql('CREATE TABLE items_versions (
            id VARCHAR(255) NOT NULL, 
            version INT NOT NULL, 
            content BYTEA NOT NULL, 
            hash VARCHAR(255) NOT NULL, 
            PRIMARY KEY(id, version)
        )');
    }

    public function down(Schema $schema) : void
    {
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP TABLE items_items');
        $this->addSql('DROP TABLE items_versions');
    }
}
