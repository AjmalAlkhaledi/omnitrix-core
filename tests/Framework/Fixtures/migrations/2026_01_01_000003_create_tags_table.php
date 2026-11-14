<?php

declare(strict_types=1);

use Omnitrix\Database\Migrations\Migration;
use Omnitrix\Database\Schema\Blueprint;
use Omnitrix\Database\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('tags', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });
        $schema->create('post_tag', static function (Blueprint $table): void {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('post_tag');
        $schema->dropIfExists('tags');
    }
};
