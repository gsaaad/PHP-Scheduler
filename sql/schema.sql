-- Database Schema for Robot Scheduling System (Aligned with Seeder)

-- 1. Departments
CREATE TABLE IF NOT EXISTS departments (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    building_code VARCHAR(20)
);

-- 2. Roles
CREATE TABLE IF NOT EXISTS roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    can_schedule BOOLEAN DEFAULT FALSE,
    can_maintain BOOLEAN DEFAULT FALSE
);

-- 3. Users
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    department_id INT REFERENCES departments(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. User Roles
CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    role_id INT REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

-- 5. Arenas
CREATE TABLE IF NOT EXISTS arenas (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50)
);

-- 6. Capabilities
CREATE TABLE IF NOT EXISTS capabilities (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
);

-- 7. Tasks
CREATE TABLE IF NOT EXISTS tasks (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    required_capability_id INT REFERENCES capabilities(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Robots
CREATE TABLE IF NOT EXISTS robots (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    status VARCHAR(20) DEFAULT 'idle',
    battery_level INT CHECK (battery_level >= 0 AND battery_level <= 100),
    model_number VARCHAR(50),
    serial_number VARCHAR(100) UNIQUE,
    firmware_version VARCHAR(20),
    current_location_lat DECIMAL(10, 8),
    current_location_lng DECIMAL(11, 8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 9. Robot Departments (Many-to-Many)
CREATE TABLE IF NOT EXISTS robot_departments (
    robot_id INT REFERENCES robots(id) ON DELETE CASCADE,
    department_id INT REFERENCES departments(id) ON DELETE CASCADE,
    PRIMARY KEY (robot_id, department_id)
);

-- 10. Robot Arenas (Many-to-Many)
CREATE TABLE IF NOT EXISTS robot_arenas (
    robot_id INT REFERENCES robots(id) ON DELETE CASCADE,
    arena_id INT REFERENCES arenas(id) ON DELETE CASCADE,
    PRIMARY KEY (robot_id, arena_id)
);

-- 11. Robot Capabilities (Many-to-Many)
CREATE TABLE IF NOT EXISTS robot_capabilities (
    robot_id INT REFERENCES robots(id) ON DELETE CASCADE,
    capability_id INT REFERENCES capabilities(id) ON DELETE CASCADE,
    PRIMARY KEY (robot_id, capability_id)
);

-- 12. Schedules
CREATE TABLE IF NOT EXISTS schedules (
    id SERIAL PRIMARY KEY,
    robot_id INT REFERENCES robots(id) ON DELETE CASCADE,
    task_id INT REFERENCES tasks(id) ON DELETE CASCADE,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 13. Audit Logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 14. Maintenance Logs
CREATE TABLE IF NOT EXISTS maintenance_logs (
    id SERIAL PRIMARY KEY,
    robot_id INT REFERENCES robots(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    cost DECIMAL(10, 2),
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 15. Firmware Updates
CREATE TABLE IF NOT EXISTS firmware_updates (
    id SERIAL PRIMARY KEY,
    version VARCHAR(20) UNIQUE NOT NULL,
    description TEXT,
    release_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
