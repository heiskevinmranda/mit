# Certificate Management Feature Implementation

## Overview
This feature allows users to upload, manage, and track their certificates (technical, academic, professional, etc.). Managers and administrators can approve/reject certificates and manage them.

## Database Changes
- Created `certificates` table with fields for certificate details
- Added indexes for efficient querying
- Created database triggers for automatic timestamps
- Created `certificate_dashboard` view for management

## Files Created

### Database Setup
- `create_certificates_table.sql` - SQL script to create the certificates table
- `install_certificates_table.php` - PHP script to execute the SQL

### AJAX Endpoints
- `ajax/upload_certificate.php` - Handle certificate uploads and retrieval
- `ajax/manage_certificates.php` - Handle approval/rejection of certificates
- `ajax/download_certificate.php` - Secure download of certificate files

### User Interface
- `includes/certificate_components.php` - Frontend components and JavaScript
- `pages/staff/profile.php` - Updated to include certificate management
- `pages/certificates/admin_manage.php` - Admin certificate management dashboard
- `pages/certificates/export.php` - Export functionality for certificates

### Permissions
- `includes/permissions.php` - Updated with certificate-specific permission functions

### Testing
- `test_certificates.php` - Test script to validate functionality

## Features Implemented

### For Regular Users
- Upload certificates to their profile
- View their own certificates
- Download their own certificates
- Edit/delete their own certificates

### For Administrators/Managers
- View all certificates
- Approve/reject certificates
- Bulk approve/reject certificates
- Export certificates to CSV/PDF
- View certificate management dashboard
- Filter certificates by status/user

### Security Features
- Role-based access control
- File type validation
- File size limits (5MB)
- Secure file storage
- Proper permission checks

## Technical Details

### Database Schema
The `certificates` table includes:
- `user_id` - Foreign key to users table
- `certificate_name` - Name of the certificate
- `certificate_type` - Type (technical, academic, professional, etc.)
- `issuing_organization` - Organization that issued the certificate
- `issue_date` - Date certificate was issued
- `expiry_date` - Date certificate expires
- `certificate_number` - Certificate number
- `file_path`, `file_name`, `file_size`, `mime_type` - File information
- `status`, `approval_status` - Tracking approval status
- `approved_by`, `approval_notes`, `rejection_reason` - Approval information

### Supported File Types
- PDF
- JPG/JPEG
- PNG
- GIF

### Upload Directory
- Certificates are stored in `/uploads/certificates/{user_id}/`

## Integration Points
- Integrated into user profile pages
- Added to sidebar navigation
- Added permission checks throughout the application

## Usage Instructions

### For Users
1. Navigate to your profile page
2. Click "Manage Certificates" button
3. Use the upload modal to add new certificates
4. View and download your certificates

### For Administrators
1. Navigate to "Certificate Management" in the sidebar
2. View pending certificates awaiting approval
3. Approve or reject certificates as needed
4. Use bulk actions for multiple certificates
5. Export certificates as needed

## Permissions Matrix

| Action | Super Admin | Admin | Manager | Support Tech | Client |
|--------|-------------|-------|---------|--------------|--------|
| Upload own certs | ✓ | ✓ | ✓ | ✓ | ✓ |
| View own certs | ✓ | ✓ | ✓ | ✓ | ✓ | 
| Download own certs | ✓ | ✓ | ✓ | ✓ | ✓ |
| View all certs | ✓ | ✓ | ✓ | ✗ | ✗ |
| Approve certs | ✓ | ✓ | ✓ | ✗ | ✗ |
| Reject certs | ✓ | ✓ | ✓ | ✗ | ✗ |
| Bulk actions | ✓ | ✓ | ✓ | ✗ | ✗ |
| Export certs | ✓ | ✓ | ✓ | ✗ | ✗ |

## Security Considerations
- All file uploads are validated for type and size
- File paths are not exposed directly
- Access is controlled by user roles
- Files are stored in user-specific directories
- Proper sanitization of all inputs

## Testing
- Database schema validation
- Endpoint accessibility
- File upload functionality
- Permission system
- Sample data creation and cleanup