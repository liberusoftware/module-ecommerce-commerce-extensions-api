<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('api_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('team_id')->nullable();
            $table->json('abilities');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_actors');
    }
};
