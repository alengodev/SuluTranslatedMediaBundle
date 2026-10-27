<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Flexible additional media data + Sulu 3.1 origin adoption.
 *
 * - Adds `additional_data` (JSON) to `me_media` (unlocalized keys) and
 *   `me_media_translations` (localized keys).
 * - Carries the retired `me_media.ai_generated` flag into Sulu's native
 *   `me_file_versions.origin` ('ai_generated') for every file version of an
 *   AI-flagged media.
 * - Moves `me_media.verify_download` and `me_media_translations.title`/`description`
 *   into the new JSON columns, then drops the old columns.
 *
 * Targets MySQL/MariaDB.
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flexible additional_data JSON columns; carry ai_generated into Sulu FileVersion.origin';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be run on MySQL/MariaDB.',
        );

        // Measure the carry-over up front, while the legacy columns still exist.
        $aiCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM me_file_versions fv'
            . ' INNER JOIN me_files f ON fv.idFiles = f.id'
            . ' INNER JOIN me_media m ON f.idMedia = m.id'
            . ' WHERE m.ai_generated = 1',
        );
        $verifyCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM me_media WHERE verify_download IS NOT NULL',
        );
        $translationCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM me_media_translations WHERE title IS NOT NULL OR description IS NOT NULL',
        );

        $this->write(\sprintf('     -> carrying ai_generated into FileVersion.origin for %d file version(s)', $aiCount));
        $this->write(\sprintf('     -> packing verify_download into me_media.additional_data for %d media', $verifyCount));
        $this->write(\sprintf('     -> packing title/description into me_media_translations.additional_data for %d translation(s)', $translationCount));

        $this->addSql('ALTER TABLE me_media ADD additional_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE me_media_translations ADD additional_data JSON DEFAULT NULL');

        // ai_generated -> Sulu FileVersion.origin (all file versions of the media)
        $this->addSql(
            "UPDATE me_file_versions fv"
            . " INNER JOIN me_files f ON fv.idFiles = f.id"
            . " INNER JOIN me_media m ON f.idMedia = m.id"
            . " SET fv.origin = 'ai_generated' WHERE m.ai_generated = 1",
        );

        // verify_download -> me_media.additional_data (unlocalized)
        $this->addSql(
            "UPDATE me_media SET additional_data = JSON_OBJECT('verify_download', verify_download = 1)"
            . ' WHERE verify_download IS NOT NULL',
        );

        // title/description -> me_media_translations.additional_data (localized)
        $this->addSql(
            "UPDATE me_media_translations SET additional_data = JSON_OBJECT('title', title, 'description', description)"
            . ' WHERE title IS NOT NULL OR description IS NOT NULL',
        );

        $this->addSql('ALTER TABLE me_media DROP ai_generated, DROP verify_download');
        $this->addSql('ALTER TABLE me_media_translations DROP title, DROP description');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be run on MySQL/MariaDB.',
        );

        $this->addSql('ALTER TABLE me_media ADD ai_generated TINYINT(1) DEFAULT NULL, ADD verify_download TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE me_media_translations ADD title VARCHAR(255) DEFAULT NULL, ADD description LONGTEXT DEFAULT NULL');

        $this->addSql(
            "UPDATE me_media SET verify_download = CASE WHEN JSON_EXTRACT(additional_data, '$.verify_download') = true THEN 1 ELSE 0 END"
            . ' WHERE additional_data IS NOT NULL',
        );
        $this->addSql(
            "UPDATE me_media_translations SET"
            . " title = JSON_UNQUOTE(JSON_EXTRACT(additional_data, '$.title')),"
            . " description = JSON_UNQUOTE(JSON_EXTRACT(additional_data, '$.description'))"
            . ' WHERE additional_data IS NOT NULL',
        );

        // origin is Sulu-owned and intentionally not reverted.

        $this->addSql('ALTER TABLE me_media DROP additional_data');
        $this->addSql('ALTER TABLE me_media_translations DROP additional_data');
    }
}
