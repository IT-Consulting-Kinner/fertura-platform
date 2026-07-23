-- Fixture table for the module unique-violation net demonstration: a tenant-scoped
-- table with a tenant-first unique on `name` (built via the Core helpers, so it is
-- tenant-conformant and passes the install gate). DupPage inserts into it WITHOUT any
-- pre-check, so a duplicate name raises a raw 23505.
SELECT core.create_tenant_table('mod_zztest_web', 'things', 'id uuid PRIMARY KEY DEFAULT gen_random_uuid(), name text NOT NULL');
SELECT core.add_tenant_unique('mod_zztest_web', 'things', 'uq_zzweb_things_name', 'name');
