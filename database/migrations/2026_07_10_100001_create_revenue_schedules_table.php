<?php // 2026_07_10_100001_create_revenue_schedules_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('revenue_schedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->date('start_date');
            $t->unsignedInteger('periods');
            $t->foreignId('deferred_account_id')->constrained('accounts');
            $t->foreignId('revenue_account_id')->constrained('accounts');
            $t->string('status')->default('active'); // active, completed, cancelled
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('revenue_schedules'); }
};
