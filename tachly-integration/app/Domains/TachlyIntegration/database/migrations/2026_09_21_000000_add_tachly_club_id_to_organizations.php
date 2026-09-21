<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External key linking a Gäld Organization back to the Tachly club that
 * provisioned it. Nullable + unique: existing/manually-created
 * organizations (e.g. the original GlaStar Flyers evaluation org) are
 * unaffected and simply have no Tachly linkage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tachly_club_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('tachly_club_id');
        });
    }
};
