-- SQL script to add profile picture column to users table
-- Run this script as a database administrator

-- Add profile_picture column to users table
ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255);

-- Optional: Add comments to document the new column
COMMENT ON COLUMN users.profile_picture IS 'User profile picture URL or file path';

-- Verify the column was added
SELECT column_name, data_type, is_nullable 
FROM information_schema.columns 
WHERE table_name = 'users' 
AND column_name = 'profile_picture';