-- SQL to create ticket_notifications table for tracking sent notifications
-- Prevents duplicate notifications being sent for the same ticket/rule

CREATE TABLE IF NOT EXISTS ticket_notifications (
    id SERIAL PRIMARY KEY,
    ticket_id INTEGER NOT NULL,
    notification_type VARCHAR(50) NOT NULL, -- 'stale_update', 'stale_close'
    sent_to_user_id INTEGER,
    sent_to_manager_id INTEGER,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    email_sent_to VARCHAR(255),
    email_sent_to_manager VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_ticket_notification UNIQUE (ticket_id, notification_type, sent_to_user_id)
);

-- Create index for faster queries
CREATE INDEX IF NOT EXISTS idx_ticket_notifications_ticket_id 
ON ticket_notifications(ticket_id);

CREATE INDEX IF NOT EXISTS idx_ticket_notifications_type 
ON ticket_notifications(notification_type);

CREATE INDEX IF NOT EXISTS idx_ticket_notifications_sent_at 
ON ticket_notifications(sent_at);

-- Comment describing the table
COMMENT ON TABLE ticket_notifications IS 'Tracks sent email notifications for tickets to prevent duplicates';
COMMENT ON COLUMN ticket_notifications.notification_type IS 'Types: stale_update (2 days unupdated), stale_close (5 days unclosed)';
