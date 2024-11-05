<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingColumnsToMenusTable extends Migration
{
    public function up()
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('subcategory')->nullable();
            $table->string('discount')->nullable();
            $table->text('additional_details')->nullable();
            $table->string('price')->change();
        });
    }

    public function down()
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['subcategory', 'discount', 'additional_details']);
            $table->decimal('price', 8, 2)->change();
        });
    }
}
