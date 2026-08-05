-- 002 access control: authentication + attribute-based robot access.
--
-- Access is NOT a single fixed dimension. A lab's reach over the fleet is
-- expressed as a set of rules:
--
--   a robot is accessible to a department if ANY rule matches,
--   and a rule matches when ALL of its criteria hold.
--
-- That two-level shape covers the real cases:
--   "all robots in Chem Lab 1"          -> 1 rule, 1 arena criterion
--   "robots that walk AND swim"         -> 1 rule, 2 capability criteria (AND)
--   "anything that swims OR floats"     -> 2 rules, 1 capability criterion each (OR)
--   "biology robots only"               -> 1 rule, 1 department or type criterion
--   unrestricted (fleet admin)          -> 1 rule with zero criteria

-- ---------------------------------------------------------------- auth

CREATE TABLE IF NOT EXISTS api_tokens (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    -- SHA-256 of the presented token. The plaintext is shown once, at creation,
    -- and never stored -- a leaked database yields no usable credentials.
    token_hash CHAR(64) UNIQUE NOT NULL,
    last_used_at TIMESTAMP,
    expires_at TIMESTAMP,
    revoked_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens (user_id);

CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(64) PRIMARY KEY,             -- SHA-256 of the session cookie value
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions (expires_at);

-- Fleet-wide administrator bypasses access rules entirely.
ALTER TABLE roles ADD COLUMN IF NOT EXISTS is_admin BOOLEAN NOT NULL DEFAULT FALSE;

-- ------------------------------------------------------- access rules

CREATE TABLE IF NOT EXISTS access_rules (
    id SERIAL PRIMARY KEY,
    department_id INT NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    description TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_access_rules_department ON access_rules (department_id);

CREATE TABLE IF NOT EXISTS access_rule_criteria (
    id SERIAL PRIMARY KEY,
    rule_id INT NOT NULL REFERENCES access_rules(id) ON DELETE CASCADE,
    -- arena      -> robot is in robot_arenas for this arena
    -- capability -> robot holds this capability
    -- department -> robot is assigned to this department
    -- type       -> robots.type equals this value
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('arena', 'capability', 'department', 'type')),
    ref_id INT,               -- for arena / capability / department
    ref_value VARCHAR(100),   -- for type
    CONSTRAINT criteria_ref_present CHECK (
        (kind = 'type' AND ref_value IS NOT NULL)
        OR (kind <> 'type' AND ref_id IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_access_criteria_rule ON access_rule_criteria (rule_id);

-- ------------------------------------------------------------- audit

-- The baseline audit_logs table has no way to point at what was acted upon.
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50);
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS entity_id INT;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45);
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS outcome VARCHAR(20) NOT NULL DEFAULT 'success';

CREATE INDEX IF NOT EXISTS idx_audit_user_time ON audit_logs (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs (entity_type, entity_id);
