-- SQL script to add CSR S/N column to tickets table
-- Run this script as a database administrator

-- Add csr_sn column to tickets table
ALTER TABLE tickets ADD COLUMN csr_sn VARCHAR(255);

-- Optional: Add comments to document the new column
COMMENT ON COLUMN tickets.csr_sn IS 'Customer Service Report Serial Number (optional field)';

-- Verify the column was added
SELECT column_name, data_type, is_nullable 
FROM information_schema.columns 
WHERE table_name = 'tickets' 
AND column_name = 'csr_sn';