-- Create certificates table for storing user certificates
CREATE TABLE IF NOT EXISTS certificates (
    id SERIAL PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    certificate_name VARCHAR(255) NOT NULL,
    certificate_type VARCHAR(100) NOT NULL, -- technical, academic, professional, etc.
    issuing_organization VARCHAR(255),
    issue_date DATE,
    expiry_date DATE,
    certificate_number VARCHAR(100),
    file_path VARCHAR(500), -- Path to uploaded certificate file
    file_name VARCHAR(255), -- Original file name
    file_size BIGINT, -- File size in bytes
    mime_type VARCHAR(100), -- MIME type of the file
    status VARCHAR(50) DEFAULT 'pending', -- pending, approved, rejected, expired
    approval_status VARCHAR(50) DEFAULT 'pending', -- pending, approved, rejected
    approved_by UUID REFERENCES users(id), -- Who approved/rejected
    approval_notes TEXT, -- Notes from approver
    rejection_reason TEXT, -- Reason for rejection
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP DEFAULT NULL,
    rejected_at TIMESTAMP DEFAULT NULL
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_certificates_user_id ON certificates(user_id);
CREATE INDEX IF NOT EXISTS idx_certificates_status ON certificates(status);
CREATE INDEX IF NOT EXISTS idx_certificates_approval_status ON certificates(approval_status);
CREATE INDEX IF NOT EXISTS idx_certificates_certificate_type ON certificates(certificate_type);
CREATE INDEX IF NOT EXISTS idx_certificates_expiry_date ON certificates(expiry_date);

-- Create trigger to update the updated_at timestamp
CREATE OR REPLACE FUNCTION update_certificates_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_certificates_updated_at_trigger
    BEFORE UPDATE ON certificates
    FOR EACH ROW
    EXECUTE FUNCTION update_certificates_updated_at();

-- Insert sample certificate types for reference
INSERT INTO certificates (user_id, certificate_name, certificate_type, issuing_organization, issue_date, status, approval_status) 
VALUES 
    ('00000000-0000-0000-0000-000000000000', 'Sample Technical Certificate', 'technical', 'Microsoft', '2023-01-15', 'approved', 'approved'),
    ('00000000-0000-0000-0000-000000000000', 'Sample Academic Certificate', 'academic', 'University', '2022-06-30', 'approved', 'approved')
ON CONFLICT DO NOTHING;

-- Create view for certificate management dashboard
CREATE OR REPLACE VIEW certificate_dashboard AS
SELECT 
    c.id,
    c.user_id,
    u.email as user_email,
    sp.full_name as user_name,
    sp.designation,
    sp.department,
    c.certificate_name,
    c.certificate_type,
    c.issuing_organization,
    c.issue_date,
    c.expiry_date,
    c.status,
    c.approval_status,
    c.file_name,
    c.file_size,
    c.created_at,
    c.updated_at,
    approver.full_name as approved_by_name,
    c.approval_notes,
    c.rejection_reason
FROM certificates c
LEFT JOIN users u ON c.user_id = u.id
LEFT JOIN staff_profiles sp ON c.user_id = sp.user_id
LEFT JOIN staff_profiles approver ON c.approved_by = approver.user_id;

-- Grant permissions
GRANT SELECT, INSERT, UPDATE, DELETE ON certificates TO PUBLIC;
GRANT USAGE, SELECT ON SEQUENCE certificates_id_seq TO PUBLIC;