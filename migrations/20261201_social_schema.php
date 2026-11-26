<?php

/**
 * Migration: 20261201_social_schema.php
 *
 * Creates the socialbored tables. The same idempotent routine is used at
 * runtime by SocialBored\Schema::ensure(), so running this migration from the
 * CLI and running the plugin on the web both converge on the same schema.
 */

require_once dirname(__DIR__) . '/src/Schema.php';

class SocialSchema
{
    public function irreversible(): bool
    {
        return false;
    }

    public function up(PDO $pdo): void
    {
        \SocialBored\Schema::ensureTables($pdo);
        try {
            $pdo->prepare("DELETE FROM social_meta WHERE k = 'schema_version'")->execute();
            $pdo->prepare("INSERT INTO social_meta (k, v) VALUES ('schema_version', ?)")->execute([(string)\SocialBored\Schema::VERSION]);
        } catch (Throwable $e) {
        }
    }

    public function down(PDO $pdo): void
    {
        // Social data is preserved on uninstall. Dropping tables here would
        // destroy user content without an explicit administrator decision; the
        // admin page exposes a separate "delete social data" action instead.
    }
}
