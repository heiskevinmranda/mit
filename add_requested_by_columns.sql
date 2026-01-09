-- SQL script to add requested_by columns to tickets table
-- Run this script as a database administrator

-- Add requested_by column to tickets table
ALTER TABLE tickets ADD COLUMN requested_by VARCHAR(255);

-- Add requested_by_email column to tickets table  
ALTER TABLE tickets ADD COLUMN requested_by_email VARCHAR(255);

-- Optional: Add comments to document the new columns
COMMENT ON COLUMN tickets.requested_by IS 'Name of the person who requested the ticket';
COMMENT ON COLUMN tickets.requested_by_email IS 'Email of the person who requested the ticket';

-- Verify the columns were added
SELECT column_name, data_type, is_nullable 
FROM information_schema.columns 
WHERE table_name = 'tickets' 
AND column_name IN ('requested_by', 'requested_by_email');