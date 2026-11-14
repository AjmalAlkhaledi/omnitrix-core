<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Database;

use Omnitrix\Core\Database\Query\Expression;
use Omnitrix\Database\Schema\Blueprint;
use Omnitrix\Database\Schema\ColumnDefinition;
use Omnitrix\Database\Schema\ForeignKeyDefinition;
use Omnitrix\Database\Schema\SchemaGrammar;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SchemaGrammar::class)]
#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(ForeignKeyDefinition::class)]
final class SchemaGrammarTest extends TestCase
{
    public function testCreateTableWithColumnsIndexesAndForeignKeys(): void
    {
        $table = new Blueprint('posts', creating: true);
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('title', 200);
        $table->string('slug')->unique();
        $table->text('body')->nullable();
        $table->enum('status', ['draft', 'published'])->default('draft');
        $table->boolean('featured')->default(false);
        $table->decimal('price', 10, 2)->unsigned()->default(0);
        $table->json('meta')->nullable();
        $table->timestamp('published_at')->nullable()->index();
        $table->dateTime('checked_at')->default(new Expression('CURRENT_TIMESTAMP'));
        $table->timestamps();
        $table->index(['user_id', 'status']);

        $sql = (new SchemaGrammar())->compileCreate($table);

        self::assertSame(
            "CREATE TABLE `posts` (\n"
            . "    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    `user_id` BIGINT UNSIGNED NOT NULL,\n"
            . "    `title` VARCHAR(200) NOT NULL,\n"
            . "    `slug` VARCHAR(255) NOT NULL,\n"
            . "    `body` TEXT NULL,\n"
            . "    `status` ENUM('draft', 'published') NOT NULL DEFAULT 'draft',\n"
            . "    `featured` TINYINT(1) NOT NULL DEFAULT 0,\n"
            . "    `price` DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    `meta` JSON NULL,\n"
            . "    `published_at` TIMESTAMP NULL,\n"
            . "    `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "    `created_at` TIMESTAMP NULL,\n"
            . "    `updated_at` TIMESTAMP NULL,\n"
            . "    UNIQUE KEY `posts_slug_unique` (`slug`),\n"
            . "    KEY `posts_published_at_index` (`published_at`),\n"
            . "    KEY `posts_user_id_status_index` (`user_id`, `status`),\n"
            . "    CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            $sql,
        );
    }

    public function testAlterTableCombinesChangesIntoOneStatement(): void
    {
        $table = new Blueprint('users', creating: false);
        $table->string('nickname', 50)->nullable()->after('name');
        $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
        $table->dropColumn('legacy');
        $table->renameColumn('mail', 'email');
        $table->dropIndex('users_mail_unique');

        self::assertSame(
            ['ALTER TABLE `users` DROP INDEX `users_mail_unique`, RENAME COLUMN `mail` TO `email`, '
            . 'ADD COLUMN `nickname` VARCHAR(50) NULL AFTER `name`, ADD COLUMN `team_id` BIGINT UNSIGNED NULL, '
            . 'ADD CONSTRAINT `users_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT, '
            . 'DROP COLUMN `legacy`'],
            (new SchemaGrammar())->compileAlter($table),
        );
    }

    public function testDefaultsAndIdentifiersAreQuoted(): void
    {
        $table = new Blueprint('we`ird', creating: true);
        $table->string('note')->default("it's \\ fine")->comment('a "note"');

        $sql = (new SchemaGrammar())->compileCreate($table);

        self::assertStringContainsString('CREATE TABLE `we``ird`', $sql);
        self::assertStringContainsString("DEFAULT 'it''s \\\\ fine' COMMENT 'a \"note\"'", $sql);
    }

    public function testDropAndRename(): void
    {
        $grammar = new SchemaGrammar();

        self::assertSame('DROP TABLE IF EXISTS `posts`', $grammar->compileDrop('posts', ifExists: true));
        self::assertSame('RENAME TABLE `posts` TO `articles`', $grammar->compileRename('posts', 'articles'));
        self::assertSame([], $grammar->compileAlter(new Blueprint('posts', creating: false)));
    }
}
