<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reuse an existing AI item picture for a post whose name is a variant of one we already have,
 * instead of generating a new one. docs/developers/reference/ai-item-images.md.
 *
 * ai_image_aliases: an item name that reuses another name's picture ("Sofas" -> "Sofa"). The Go
 * API reads it so the post form shows the picture straight away; a moderator deleting the
 * picture from such a post removes the alias rather than counting against the picture.
 *
 * ai_images_embeddings: the embedding of each picture's name, for the nearest-name match. Only
 * pictures that a post has used get one, so job illustrations never match a post.
 *
 * Two new, empty tables. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_image_aliases')) {
            Schema::create('ai_image_aliases', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('name', 255)->unique('ai_image_aliases_name');
                $t->unsignedBigInteger('aiimageid');
                $t->string('method', 16);
                $t->double('score')->nullable();
                $t->timestamp('created')->useCurrent();
                $t->index('aiimageid', 'ai_image_aliases_aiimageid');
                $t->foreign('aiimageid')->references('id')->on('ai_images')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('ai_images_embeddings')) {
            Schema::create('ai_images_embeddings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('aiimageid')->unique('ai_images_embeddings_aiimageid');
                $t->binary('embedding');
                $t->timestamp('created')->useCurrent();
                $t->foreign('aiimageid')->references('id')->on('ai_images')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_image_aliases');
        Schema::dropIfExists('ai_images_embeddings');
    }
};
