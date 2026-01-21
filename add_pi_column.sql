-- SQL script to add Proforma Invoice (PI) field to tickets table
-- Run this script as a database administrator

-- Add pi_number column to tickets table
ALTER TABLE tickets ADD COLUMN pi_number VARCHAR(255);

-- Add comment to document the new column
COMMENT ON COLUMN tickets.pi_number IS 'Proforma Invoice Number';

-- Verify the column was added
SELECT column_name, data_type, is_nullable 
FROM information_schema.columns 
WHERE table_name = 'tickets' 
AND column_name = 'pi_number';