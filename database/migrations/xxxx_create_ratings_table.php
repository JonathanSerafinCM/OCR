
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('dish_id')->constrained('menus')->onDelete('cascade');
            $table->integer('rating')->unsigned();
            $table->timestamps();
            
            $table->unique(['user_id', 'dish_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ratings');
    }
};