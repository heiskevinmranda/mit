-- Create client_locations table
CREATE TABLE client_locations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id UUID NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    
    -- Basic Information
    location_name VARCHAR(200) NOT NULL,
    location_type VARCHAR(50) DEFAULT 'Other',
    is_primary BOOLEAN DEFAULT FALSE,
    
    -- Address Information
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100),
    postal_code VARCHAR(20),
    country VARCHAR(2) NOT NULL,
    
    -- Contact Information
    primary_contact VARCHAR(200),
    phone VARCHAR(50),
    email VARCHAR(200),
    
    -- Additional Information
    notes TEXT,
    
    -- Audit Fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_client_locations_client_id (client_id),
    INDEX idx_client_locations_is_primary (is_primary),
    INDEX idx_client_locations_country (country),
    
    -- Constraint: Only one primary location per client
    CONSTRAINT unique_primary_per_client UNIQUE NULLS NOT DISTINCT (client_id, is_primary)
);

-- Add comment to table
COMMENT ON TABLE client_locations IS 'Stores client physical locations';

-- Add comments to columns
COMMENT ON COLUMN client_locations.location_name IS 'Descriptive name of the location';
COMMENT ON COLUMN client_locations.location_type IS 'Type of location (Headquarters, Branch, etc.)';
COMMENT ON COLUMN client_locations.is_primary IS 'Whether this is the primary location';
COMMENT ON COLUMN client_locations.country IS 'ISO country code (2 letters)';

-- Trigger for updated_at
CREATE TRIGGER update_client_locations_updated_at 
    BEFORE UPDATE ON client_locations 
    FOR EACH ROW 
    EXECUTE FUNCTION update_updated_at_column();