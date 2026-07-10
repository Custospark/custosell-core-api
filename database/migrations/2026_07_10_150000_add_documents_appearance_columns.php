<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_folders', function (Blueprint $table) {
            $table->string('cover_color', 7)->nullable()->after('visibility');
        });

        Schema::table('document_tags', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('slug');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('documents_cover_color', 7)->nullable()->after('logo_path');
            $table->string('documents_background_type', 16)->nullable()->after('documents_cover_color');
            $table->string('documents_background_value', 500)->nullable()->after('documents_background_type');
        });
    }

    public function down(): void
    {
        Schema::table('document_folders', function (Blueprint $table) {
            $table->dropColumn('cover_color');
        });

        Schema::table('document_tags', function (Blueprint $table) {
            $table->dropColumn('color');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'documents_cover_color',
                'documents_background_type',
                'documents_background_value',
            ]);
        });
    }
};
