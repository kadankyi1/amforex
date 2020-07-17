<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWorkersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->increments('worker_id');
            $table->string('worker_surname', 255);
            $table->string('worker_firstname', 255);
            $table->string('worker_othernames', 255);
            $table->string('worker_home_gps_address', 255);
            $table->longText('worker_home_location');
            $table->string('worker_role', 255);
            $table->integer('worker_flagged');
            $table->string('worker_phone_number', 255)->unique();
            $table->string('worker_email', 255)->unique();
            $table->string('worker_pin', 255);
            $table->string('password', 255);
            $table->string('creator_user_type', 255);
            $table->string('creator_id', 255);
            $table->timestamps();
        });

        Schema::table('workers', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('bureau_id');

            $table->foreign('branch_id')->references('branch_id')->on('branches');
            $table->foreign('bureau_id')->references('bureau_id')->on('bureaus');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Schema::dropIfExists('workers');
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
}