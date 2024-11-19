<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('dish_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dish_id')->constrained('menus')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('viewed_at');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dish_views');
    }
};