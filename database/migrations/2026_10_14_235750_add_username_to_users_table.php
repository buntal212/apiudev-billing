<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
        });

        foreach (DB::table('users')->orderBy('id')->cursor() as $user) {
            $baseUsername = Str::of((string) $user->email)
                ->before('@')
                ->lower()
                ->replaceMatches('/[^a-z0-9_-]+/', '_')
                ->trim('_')
                ->substr(0, 50)
                ->value();
            $baseUsername = $baseUsername !== '' ? $baseUsername : 'user';
            $username = $baseUsername;
            $suffix = 2;

            while (DB::table('users')->where('username', $username)->exists()) {
                $number = '-'.$suffix;
                $username = Str::substr($baseUsername, 0, 50 - strlen($number)).$number;
                $suffix++;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
