-- 009 Support the login throttle.
--
-- LoginThrottle counts recent denied sign-ins for one address on every login
-- attempt. audit_logs is append-only and grows without bound, so that count
-- needs an index or it degrades into a sequential scan over the entire audit
-- history -- on the exact endpoint an attacker is hammering.
--
-- Partial: only 'auth.login' + 'denied' rows are ever counted, which is a small
-- fraction of the table, so the index stays small and cheap to maintain on the
-- writes that dominate (successful actions).

CREATE INDEX IF NOT EXISTS idx_audit_login_failures
    ON audit_logs (ip_address, created_at DESC)
    WHERE action = 'auth.login' AND outcome = 'denied';
