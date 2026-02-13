-- SQL script to add missing fields to tickets table
-- Run this script as a database administrator with proper privileges

-- Add Requested By field
ALTER TABLE tickets ADD COLUMN requested_by VARCHAR(255);

-- Add Requester Email field (Optional)
ALTER TABLE tickets ADD COLUMN requested_by_email VARCHAR(255);

-- Add CSR S/N (Customer Service Report Serial Number) field
ALTER TABLE tickets ADD COLUMN csr_sn VARCHAR(255);

-- Add Proforma Invoice (PI) Number field
ALTER TABLE tickets ADD COLUMN pi_number VARCHAR(255);

-- Add comments for documentation (PostgreSQL)
COMMENT ON COLUMN tickets.requested_by IS 'Name of person requesting the ticket';
COMMENT ON COLUMN tickets.requested_by_email IS 'Email of person requesting the ticket (optional)';
COMMENT ON COLUMN tickets.csr_sn IS 'Customer Service Report Serial Number (optional)';
COMMENT ON COLUMN tickets.pi_number IS 'Proforma Invoice Number (optional)';

-- Verify the columns were added successfully
SELECT 
    column_name, 
    data_type, 
    character_maximum_length, 
    is_nullable,
    column_default
FROM information_schema.columns 
WHERE table_name = 'tickets' 
AND column_name IN ('requested_by', 'requested_by_email', 'csr_sn', 'pi_number')
ORDER BY ordinal_position;

-- Show all columns to confirm position
SELECT 
    ordinal_position,
    column_name, 
    data_type, 
    character_maximum_length, 
    is_nullable
FROM information_schema.columns 
WHERE table_name = 'tickets' 
ORDER BY ordinal_position;