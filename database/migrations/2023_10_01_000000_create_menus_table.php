<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMenusTable extends Migration
{
    public function up()
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('dish_name');
            $table->decimal('price', 8, 2);
            $table->text('description')->nullable();
            $table->text('special_notes')->nullable();
            $table->timestamps();
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->string('price')->change();
        });
    }

    public function down()
    {
        Schema::dropIfExists('menus');
    }
}
