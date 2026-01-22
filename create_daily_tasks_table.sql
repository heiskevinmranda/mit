-- Create daily_tasks table for tracking daily meeting minutes and user tasks
CREATE TABLE IF NOT EXISTS daily_tasks (
    id SERIAL PRIMARY KEY,
    task_date DATE DEFAULT CURRENT_DATE,
    assigned_to UUID REFERENCES users(id),
    assigned_by UUID REFERENCES users(id),
    task_title VARCHAR(255) NOT NULL,
    task_description TEXT,
    task_status VARCHAR(50) DEFAULT 'pending', -- pending, in_progress, completed, cancelled
    priority VARCHAR(20) DEFAULT 'medium', -- low, medium, high, urgent
    due_time TIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP DEFAULT NULL,
    
    -- Ensure only one task per user per day with same title
    UNIQUE(task_date, assigned_to, task_title)
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_daily_tasks_assigned_to ON daily_tasks(assigned_to);
CREATE INDEX IF NOT EXISTS idx_daily_tasks_assigned_by ON daily_tasks(assigned_by);
CREATE INDEX IF NOT EXISTS idx_daily_tasks_date ON daily_tasks(task_date);
CREATE INDEX IF NOT EXISTS idx_daily_tasks_status ON daily_tasks(task_status);
CREATE INDEX IF NOT EXISTS idx_daily_tasks_priority ON daily_tasks(priority);

-- Create trigger to update the updated_at timestamp
CREATE OR REPLACE FUNCTION update_daily_tasks_updated_at()
RETURNS TRIGGER AS '
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
' LANGUAGE plpgsql;

CREATE TRIGGER update_daily_tasks_updated_at 
    BEFORE UPDATE ON daily_tasks 
    FOR EACH ROW 
    EXECUTE FUNCTION update_daily_tasks_updated_at();

-- Simplified Daily Tasks Table Creation Script
DROP TABLE IF EXISTS daily_tasks;

CREATE TABLE daily_tasks (
    id SERIAL PRIMARY KEY,
    task_date DATE DEFAULT CURRENT_DATE,
    task_title VARCHAR(255) NOT NULL,
    task_description TEXT,
    task_status VARCHAR(50) DEFAULT 'pending',
    priority VARCHAR(20) DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for better performance
CREATE INDEX idx_daily_tasks_date ON daily_tasks(task_date);
CREATE INDEX idx_daily_tasks_status ON daily_tasks(task_status);
CREATE INDEX idx_daily_tasks_priority ON daily_tasks(priority);
