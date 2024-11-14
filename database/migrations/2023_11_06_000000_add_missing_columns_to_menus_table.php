<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingColumnsToMenusTable extends Migration
{
    public function up()
    {
        Schema::table('menus', function (Blueprint $table) {
            // $table->string('name')->nullable(); // Elimina si fue añadido antes
            $table->string('subcategory')->nullable();
            $table->string('discount')->nullable();
            $table->text('additional_details')->nullable();
            $table->string('price')->change();
        });
    }

    public function down()
    {
        Schema::table('menus', function (Blueprint $table) {
            // $table->dropColumn('name'); // Elimina si fue añadido antes
            $table->dropColumn(['subcategory', 'discount', 'additional_details', 'dish_name']);
            $table->decimal('price', 8, 2)->change();
        });
    }
}
