<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // --->>> اصلاح شد: نوع داده باید با persons.n_code مطابقت داشته باشد
            $table->string('n_code', 10)->unique()->index();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            // تعریف محدودیت کلید خارجی صحیح است و onDelete('restrict') کار مورد نظر شما را انجام می‌دهد
            $table->foreign('n_code')
                ->references('n_code')->on('persons')
                ->onDelete('restrict') // <-- این باعث می‌شود حذف person محدود شود اگر user مرتبط وجود داشته باشد
                ->onUpdate('cascade');
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

