<?php

declare(strict_types=1);

namespace Omnitrix\Database\Migrations;

use Omnitrix\Database\Schema\Schema;

/**
 * A schema change. Migration files in `database/migrations/` return an instance:
 *
 * ```php
 * return new class extends Migration {
 *     public function up(Schema $schema): void
 *     {
 *         $schema->create('users', function (Blueprint $table): void {
 *             $table->id();
 *             $table->string('email')->unique();
 *             $table->timestamps();
 *         });
 *     }
 *
 *     public function down(Schema $schema): void
 *     {
 *         $schema->dropIfExists('users');
 *     }
 * };
 * ```
 */
abstract class Migration
{
    /** Connection name from the `database` configuration; `null` uses the default connection. */
    public ?string $connection = null;

    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void {}
}
