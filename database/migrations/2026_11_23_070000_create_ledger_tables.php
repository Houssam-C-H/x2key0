<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry money ledger (spec 5.12 / Part 7) and the separate points ledger (5.13 / 7.7).
 * Debit +, credit −; every journal sums to zero per currency (invariant 4.3 #1); journals and postings are
 * append-only. On PostgreSQL both rules are also enforced by triggers: a deferred constraint trigger checks
 * the zero sum at commit, and row triggers refuse UPDATE/DELETE. Balances are a projection written only by
 * PostJournal / PostPointsJournal under row locks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48);
            $table->string('type', 12); // asset | liability | revenue | expense | equity
            $table->string('owner_type', 16)->default('platform'); // platform | user
            $table->unsignedBigInteger('owner_id')->default(0);     // 0 for platform accounts (keeps the unique index effective)
            $table->char('currency', 3);
            $table->timestampTz('created_at');
            $table->unique(['code', 'owner_type', 'owner_id', 'currency']);
        });

        Schema::create('ledger_journals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 32)->index();
            $table->string('source_type', 24)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('created_by_type', 16)->default('system');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->timestampTz('posted_at');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('ledger_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('ledger_journals');
            $table->foreignId('account_id')->constrained('ledger_accounts');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->timestampTz('created_at');
            $table->index(['account_id', 'id']);
        });

        Schema::create('ledger_balances', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained('ledger_accounts');
            $table->bigInteger('balance')->default(0);
            $table->timestampTz('updated_at');
        });

        Schema::create('points_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16); // USER | ISSUED | REDEEMED | EXPIRED
            $table->unsignedBigInteger('owner_user_id')->default(0); // 0 for platform accounts
            $table->timestampTz('created_at');
            $table->unique(['code', 'owner_user_id']);
        });

        Schema::create('points_journals', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 24)->index(); // earn_order | earn_task | redeem_coupon | reverse | expire | adjust
            $table->string('source_type', 24)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->timestampTz('posted_at');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('points_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('points_journals');
            $table->foreignId('account_id')->constrained('points_accounts');
            $table->bigInteger('amount');
            $table->timestampTz('created_at');
            $table->index(['account_id', 'id']);
        });

        Schema::create('points_balances', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained('points_accounts');
            $table->bigInteger('balance')->default(0);
            $table->timestampTz('updated_at');
        });

        Schema::create('points_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('trigger', 24); // order_completed | first_order | comment | daily_login | affiliate_signup
            $table->unsignedInteger('amount_per_usd')->nullable();
            $table->unsignedInteger('fixed')->nullable();
            $table->boolean('multiplier_by_level')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->postgresGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_zero_sum() CASCADE; DROP FUNCTION IF EXISTS points_zero_sum() CASCADE; DROP FUNCTION IF EXISTS ledger_append_only() CASCADE;');
        }

        Schema::dropIfExists('points_rules');
        Schema::dropIfExists('points_balances');
        Schema::dropIfExists('points_postings');
        Schema::dropIfExists('points_journals');
        Schema::dropIfExists('points_accounts');
        Schema::dropIfExists('ledger_balances');
        Schema::dropIfExists('ledger_postings');
        Schema::dropIfExists('ledger_journals');
        Schema::dropIfExists('ledger_accounts');
    }

    private function postgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_zero_sum() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM ledger_postings WHERE journal_id = NEW.journal_id
                    GROUP BY currency HAVING SUM(amount) <> 0
                ) THEN
                    RAISE EXCEPTION 'ledger journal % does not sum to zero', NEW.journal_id;
                END IF;
                RETURN NULL;
            END $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER ledger_postings_zero_sum
                AFTER INSERT ON ledger_postings DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ledger_zero_sum();

            CREATE OR REPLACE FUNCTION points_zero_sum() RETURNS trigger AS $$
            BEGIN
                IF (SELECT SUM(amount) FROM points_postings WHERE journal_id = NEW.journal_id) <> 0 THEN
                    RAISE EXCEPTION 'points journal % does not sum to zero', NEW.journal_id;
                END IF;
                RETURN NULL;
            END $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER points_postings_zero_sum
                AFTER INSERT ON points_postings DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION points_zero_sum();

            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
            END $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_journals_append_only BEFORE UPDATE OR DELETE ON ledger_journals FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER ledger_postings_append_only BEFORE UPDATE OR DELETE ON ledger_postings FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER points_journals_append_only BEFORE UPDATE OR DELETE ON points_journals FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER points_postings_append_only BEFORE UPDATE OR DELETE ON points_postings FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            ALTER TABLE ledger_postings ADD CONSTRAINT ledger_postings_amount_not_zero CHECK (amount <> 0);
            ALTER TABLE points_postings ADD CONSTRAINT points_postings_amount_not_zero CHECK (amount <> 0);
            SQL);
    }
};
