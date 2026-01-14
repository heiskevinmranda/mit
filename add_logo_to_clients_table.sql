-- SQL script to add logo column to clients table
-- Run this script as a database administrator

-- Add logo_path column to clients table
ALTER TABLE clients ADD COLUMN logo_path VARCHAR(500);

-- Optional: Add comments to document the new column
COMMENT ON COLUMN clients.logo_path IS 'Path to client logo file';

-- Verify the column was added
SELECT column_name, data_type, is_nullable 
FROM information_schema.columns 
WHERE table_name = 'clients' 
AND column_name = 'logo_path';