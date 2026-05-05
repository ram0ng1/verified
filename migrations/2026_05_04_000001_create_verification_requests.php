<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('verification_requests')) {
            return;
        }

        $schema->create('verification_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('status', 20)->default('pending');
            $table->string('document_type', 32)->nullable();
            $table->string('document_path', 255)->nullable();
            $table->text('reason')->nullable();
            $table->text('admin_note')->nullable();
            $table->unsignedInteger('handled_by')->nullable();
            $table->dateTime('handled_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['status']);
            $table->index(['user_id']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('verification_requests');
    },
];
