<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use johnhenry\linkaudit\records\HostRecord;
use johnhenry\linkaudit\records\IgnoreRecord;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;

/**
 * Installation migration.
 *
 * Each table is created by its own guarded method, so a rerun on a half-built
 * schema fills in what is missing instead of failing on the first table that
 * already exists. The URLs table is created before the references table, since
 * the latter carries a foreign key to it.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class Install extends Migration
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration succeeded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        $this->_createUrlsTable();
        $this->_createScansTable();
        $this->_createReferencesTable();
        $this->_createIgnoresTable();
        $this->_createHostsTable();

        Craft::$app->getDb()->getSchema()->refresh();

        return true;
    }

    /**
     * @inheritdoc
     *
     * Dropped in foreign key order: the references table points at both the URLs
     * and the scans tables, so it goes first.
     *
     * @return bool Whether the migration succeeded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(ReferenceRecord::tableName());
        $this->dropTableIfExists(UrlRecord::tableName());
        $this->dropTableIfExists(ScanRecord::tableName());
        $this->dropTableIfExists(IgnoreRecord::tableName());
        $this->dropTableIfExists(HostRecord::tableName());

        Craft::$app->getDb()->getSchema()->refresh();

        return true;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Creates the unique URLs table: one row per normalised URL, holding the
     * cached verdict.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _createUrlsTable(): void
    {
        $table = UrlRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            // sha1 of the normalised URL. The dedupe key, because a column wide
            // enough for real URLs cannot carry a unique index.
            'urlHash' => $this->char(40)->notNull(),
            'url' => $this->text()->notNull(),
            'host' => $this->string(255)->notNull(),
            // Wide enough for the schemes nobody checks but everybody meets:
            // `itms-services`, `chrome-extension` and the like are stored as
            // ignored rows, and eight characters is not enough to hold them.
            'scheme' => $this->string(32)->notNull(),
            // Set for internal URLs, null for external ones: an HTTP verdict is
            // the same on every site, an internal path is not.
            'siteId' => $this->integer()->null(),
            'isInternal' => $this->boolean()->notNull()->defaultValue(false),
            'status' => $this->enum('status', [
                'pending',
                'ok',
                'broken',
                'redirect',
                'unreachable',
                'blocked',
                'ignored',
                'unsafe',
            ])->notNull()->defaultValue('pending'),
            'httpStatus' => $this->smallInteger()->unsigned()->null(),
            'method' => $this->enum('method', ['head', 'get'])->null(),
            'finalUrl' => $this->text()->null(),
            'redirectCount' => $this->tinyInteger()->unsigned()->notNull()->defaultValue(0),
            'redirectPermanent' => $this->boolean()->null(),
            // What the first hop of the chain answered with, 301 or 302 and the
            // rest. `httpStatus` holds where the chain ended, which is a 200 on
            // any redirect worth the name, so without this the row cannot say a
            // redirect happened at all.
            'redirectStatus' => $this->smallInteger()->unsigned()->null(),
            'reason' => $this->string(40)->null(),
            'message' => $this->text()->null(),
            'responseTimeMs' => $this->integer()->null(),
            // Consecutive failures. Gates the promotion from unreachable to
            // broken, so one bad afternoon does not flood the report.
            'failCount' => $this->integer()->notNull()->defaultValue(0),
            'dateFirstSeen' => $this->dateTime()->notNull(),
            'dateLastChecked' => $this->dateTime()->null(),
            'dateLastOk' => $this->dateTime()->null(),
            'dateLastBroken' => $this->dateTime()->null(),
            // When this URL becomes checkable again. The check phase filters on
            // it, which is what keeps a rescan cheap.
            'nextCheckAfter' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['urlHash'], true);
        $this->createIndex(null, $table, ['status', 'nextCheckAfter'], false);
        $this->createIndex(null, $table, ['host'], false);
        $this->createIndex(null, $table, ['siteId', 'isInternal'], false);
        $this->createIndex(null, $table, ['status', 'dateLastChecked'], false);

        // An internal URL only means anything in its own site.
        $this->addForeignKey(null, $table, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }

    /**
     * Creates the references table: every place a URL appears.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _createReferencesTable(): void
    {
        $table = ReferenceRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'urlId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'elementType' => $this->string(255)->notNull(),
            'siteId' => $this->integer()->notNull(),
            // The root element for a link inside a nested entry, so the control
            // panel can link to something the author can edit.
            'ownerElementId' => $this->integer()->null(),
            'fieldUid' => $this->char(36)->null(),
            'fieldHandle' => $this->string(64)->null(),
            'source' => $this->enum('source', ['field', 'rendered', 'nav'])
                ->notNull()
                ->defaultValue('field'),
            'linkText' => $this->string(255)->null(),
            'rawHref' => $this->string(500)->null(),
            'scanId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Both columns, because nearly every question the report screens ask of
        // this table is the same one: does anything on this site point at this
        // URL. On `urlId` alone the database finds the references and then reads
        // each row for its site; on both it answers out of the index. The URL
        // foreign key leans on this one too, since `urlId` leads it.
        $this->createIndex(null, $table, ['urlId', 'siteId'], false);
        $this->createIndex(null, $table, ['elementId', 'siteId'], false);
        $this->createIndex(null, $table, ['ownerElementId'], false);
        $this->createIndex(null, $table, ['scanId'], false);

        $this->addForeignKey(null, $table, ['urlId'], UrlRecord::tableName(), ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $table, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $table, ['ownerElementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $table, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
        // The reference outlives the scan that recorded it: pruned history must
        // not take content out with it.
        $this->addForeignKey(null, $table, ['scanId'], ScanRecord::tableName(), ['id'], 'SET NULL', null);
    }

    /**
     * Creates the scans table: one row per scan run.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _createScansTable(): void
    {
        $table = ScanRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->null(),
            'mode' => $this->enum('mode', ['full', 'incremental', 'single', 'check-only'])
                ->notNull()
                ->defaultValue('full'),
            'status' => $this->enum('status', [
                'queued',
                'extracting',
                'checking',
                'complete',
                'failed',
                'cancelled',
            ])->notNull()->defaultValue('queued'),
            'elementsScanned' => $this->integer()->notNull()->defaultValue(0),
            // Pages fetched over HTTP by the rendered crawl. Its own number
            // rather than part of the element count, because they answer
            // different questions: one is content read out of the database, the
            // other is requests made against this installation's own server.
            'pagesCrawled' => $this->integer()->notNull()->defaultValue(0),
            'urlsTotal' => $this->integer()->notNull()->defaultValue(0),
            'urlsChecked' => $this->integer()->notNull()->defaultValue(0),
            'urlsBroken' => $this->integer()->notNull()->defaultValue(0),
            // Null until the scan leaves the queue: a queued scan has not
            // started, and pretending otherwise skews its duration.
            'dateStarted' => $this->dateTime()->null(),
            'dateFinished' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['status'], false);
        $this->createIndex(null, $table, ['siteId', 'dateStarted'], false);

        $this->addForeignKey(null, $table, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }

    /**
     * Creates the ignores table: author decisions that outlive the reference
     * rows.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _createIgnoresTable(): void
    {
        $table = IgnoreRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'scope' => $this->enum('scope', ['url', 'host', 'pattern'])->notNull(),
            'urlHash' => $this->char(40)->null(),
            'value' => $this->string(500)->null(),
            'note' => $this->text()->null(),
            'userId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One dismissal per URL. Host and pattern rows carry a null hash, which
        // a unique index does not collide on.
        $this->createIndex(null, $table, ['scope', 'urlHash'], true);
        $this->createIndex(null, $table, ['scope'], false);

        // The decision outlives whoever made it.
        $this->addForeignKey(null, $table, ['userId'], Table::USERS, ['id'], 'SET NULL', null);
    }

    /**
     * Creates the hosts table: durable per-domain throttle and backoff state.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _createHostsTable(): void
    {
        $table = HostRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'host' => $this->string(255)->notNull(),
            'consecutiveFailures' => $this->integer()->notNull()->defaultValue(0),
            'lastStatus' => $this->smallInteger()->unsigned()->null(),
            // Learned from a 429 Retry-After, so the next scan is polite before
            // it is told to be.
            'minDelayMs' => $this->integer()->null(),
            'blockedUntil' => $this->dateTime()->null(),
            'botHostile' => $this->boolean()->notNull()->defaultValue(false),
            'dateLastRequest' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['host'], true);
    }
}
