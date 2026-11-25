<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Every single-column foreign key leads an index (ADR-035), so child lookups and cascades never scan whole tables.
 * PostgreSQL only: it is the production database and does not index foreign keys by itself.
 */
it('indexes every foreign key', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }

    $missing = DB::select(<<<'SQL'
        select c.conrelid::regclass::text || '.' || a.attname as fk
        from pg_constraint c
        join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
        where c.contype = 'f' and array_length(c.conkey, 1) = 1
          and not exists (select 1 from pg_index i where i.indrelid = c.conrelid and i.indkey[0] = c.conkey[1])
        order by 1
        SQL);

    expect(array_column($missing, 'fk'))->toBe([]);
});
