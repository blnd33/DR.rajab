<?php

use App\Enums\RateSource;
use App\Enums\RateStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only history of rates (who set them, when, from where).
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->foreign('base_currency')->references('code')->on('currencies');
            $table->foreign('quote_currency')->references('code')->on('currencies');
            $table->decimal('rate', 18, 8);
            $table->string('source', 16)->default(RateSource::Manual->value);
            $table->string('status', 16)->default(RateStatus::Confirmed->value);
            $table->timestampTz('effective_at');
            $table->foreignId('set_by')->nullable()->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['base_currency', 'quote_currency', 'status', 'effective_at']);
        });

        Pg::enumCheck('exchange_rates', 'source', RateSource::values());
        Pg::enumCheck('exchange_rates', 'status', RateStatus::values());
        Pg::check('exchange_rates', 'exchange_rates_positive', 'rate > 0');

        // Immutable rate set used by financial rows: a new row whenever a rate is confirmed.
        Schema::create('fx_snapshots', function (Blueprint $table) {
            $table->id();
            $table->decimal('usd_iqd', 18, 8);
            $table->decimal('eur_usd', 18, 8);
            $table->foreignId('usd_iqd_rate_id')->nullable()->constrained('exchange_rates');
            $table->foreignId('eur_usd_rate_id')->nullable()->constrained('exchange_rates');
            $table->timestampTz('created_at')->useCurrent();
        });

        Pg::check('fx_snapshots', 'fx_snapshots_positive', 'usd_iqd > 0 AND eur_usd > 0');
        Pg::appendOnly('fx_snapshots');
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_snapshots');
        Schema::dropIfExists('exchange_rates');
    }
};
