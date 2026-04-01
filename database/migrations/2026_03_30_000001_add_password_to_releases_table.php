<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to add password field to releases table.
 * Slug: nzb-password-field-setup
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            // Vi tilføjer 'password' kolonnen. 
            // Vi gør den nullable, da de fleste releases ikke har et password.
            if (!Schema::hasColumn('releases', 'password')) {
                $table->string('password', 255)->nullable()->after('size');
            }

            // Vi sikrer os også, at passwordstatus findes, 
            // da vi bruger den i Release.php logikken.
            if (!Schema::hasColumn('releases', 'passwordstatus')) {
                $table->tinyInteger('passwordstatus')->default(0)->after('password');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn(['password', 'passwordstatus']);
        });
    }
};