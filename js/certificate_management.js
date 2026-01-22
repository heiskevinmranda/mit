// Certificate Management JavaScript Functions

// Global variables
let currentCertificateUserId = null;
let currentCertificateId = null;

// Initialize certificate upload modal
function initCertificateUpload(userId = null) {
    currentCertificateUserId = userId || document.body.getAttribute('data-current-user-id');
    document.getElementById('certificateUserId').value = currentCertificateUserId;
    
    // Update note based on user role
    const userRole = document.body.getAttribute('data-current-user-role');
    const noteElement = document.getElementById('certificateUploadNote');
    
    if (userId && userId !== document.body.getAttribute('data-current-user-id') && 
        ['super_admin', 'admin', 'manager'].includes(userRole)) {
        noteElement.innerHTML = 'Uploading certificate for another user. You have admin privileges.';
    } else {
        noteElement.innerHTML = 'You can upload certificates for your own profile.';
    }
    
    // Reset form
    document.getElementById('certificateUploadForm').reset();
    var modal = new bootstrap.Modal(document.getElementById('certificateUploadModal'));
    modal.show();
}

// Handle certificate upload
document.addEventListener('DOMContentLoaded', function() {
    const uploadBtn = document.getElementById('uploadCertificateBtn');
    if (uploadBtn) {
        uploadBtn.addEventListener('click', function() {
            const form = document.getElementById('certificateUploadForm');
            const formData = new FormData(form);
            
            // Validation
            if (!formData.get('certificate_name') || !formData.get('certificate_type') || !formData.get('certificate_file')) {
                alert('Please fill in all required fields.');
                return;
            }
            
            // Show loading state
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading...';
            
            // Upload certificate
            fetch('/mit/ajax/upload_certificate.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Certificate uploaded successfully!');
                    var modal = bootstrap.Modal.getInstance(document.getElementById('certificateUploadModal'));
                    if (modal) {
                        modal.hide();
                    }
                    loadCertificates(currentCertificateUserId); // Refresh certificate list
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while uploading the certificate.');
            })
            .finally(() => {
                // Reset button
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-upload me-1"></i>Upload Certificate';
            });
        });
    }
});

// Load certificates for a user
function loadCertificates(userId = null) {
    const targetUserId = userId || currentCertificateUserId || document.body.getAttribute('data-current-user-id');
    
    if (!targetUserId) {
        console.error('No user ID provided for loading certificates');
        return;
    }
    
    fetch(`/mit/ajax/upload_certificate.php?user_id=${targetUserId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayCertificates(data.certificates);
            } else {
                console.error('Error loading certificates:', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Display certificates in a container
function displayCertificates(certificates) {
    const container = document.getElementById('certificatesContainer') || 
                     document.querySelector('[data-certificates-container]');
    
    if (!container) {
        console.warn('No certificates container found');
        return;
    }
    
    if (certificates.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5">
                <i class="fas fa-certificate fa-3x text-muted mb-3"></i>
                <h5>No certificates found</h5>
                <p class="text-muted">No certificates have been uploaded yet.</p>
                <button class="btn btn-primary" onclick="initCertificateUpload('${document.body.getAttribute('data-current-user-id')}')">
                    <i class="fas fa-plus me-1"></i>Upload Certificate
                </button>
            </div>
        `;
        return;
    }
    
    let html = `
        <div class="row">
            ${certificates.map(cert => `
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card certificate-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0">${escapeHtml(cert.certificate_name)}</h6>
                                <span class="badge bg-${getCertificateStatusClass(cert.approval_status)}">
                                    ${cert.approval_status}
                                </span>
                            </div>
                            
                            <p class="card-text">
                                <small class="text-muted">
                                    <i class="fas fa-tag me-1"></i>${cert.certificate_type}<br>
                                    <i class="fas fa-building me-1"></i>${cert.issuing_organization || 'N/A'}<br>
                                    ${cert.issue_date ? `<i class="fas fa-calendar me-1"></i>${formatDate(cert.issue_date)}<br>` : ''}
                                    ${cert.expiry_date ? `<i class="fas fa-clock me-1"></i>Expires: ${formatDate(cert.expiry_date)}<br>` : ''}
                                </small>
                            </p>
                            
                            <div class="mt-auto">
                                <button class="btn btn-sm btn-outline-primary me-1" onclick="viewCertificate(${cert.id})">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                                <a href="/mit/ajax/download_certificate.php?id=${cert.id}" class="btn btn-sm btn-outline-success me-1">
                                    <i class="fas fa-download me-1"></i>Download
                                </a>
                                ${canManageCertificates() ? `
                                    <button class="btn btn-sm btn-outline-warning" onclick="manageCertificate(${cert.id})">
                                        <i class="fas fa-edit me-1"></i>Manage
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
    
    container.innerHTML = html;
}

// View certificate details
function viewCertificate(certificateId) {
    currentCertificateId = certificateId;
    
    // Load certificate details
    const currentUserId = document.body.getAttribute('data-current-user-id');
    fetch(`/mit/ajax/upload_certificate.php?user_id=${currentUserId}&limit=1&offset=0`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const cert = data.certificates.find(c => c.id == certificateId);
                if (cert) {
                    displayCertificateDetails(cert);
                    var modal = new bootstrap.Modal(document.getElementById('certificateViewModal'));
                    modal.show();
                }
            }
        });
}

// Display certificate details in modal
function displayCertificateDetails(cert) {
    // Update certificate info panel
    const infoHtml = `
        <dl class="row mb-0">
            <dt class="col-sm-5">Name:</dt>
            <dd class="col-sm-7">${escapeHtml(cert.certificate_name)}</dd>
            
            <dt class="col-sm-5">Type:</dt>
            <dd class="col-sm-7">${escapeHtml(cert.certificate_type)}</dd>
            
            <dt class="col-sm-5">Organization:</dt>
            <dd class="col-sm-7">${escapeHtml(cert.issuing_organization || 'N/A')}</dd>
            
            <dt class="col-sm-5">Issue Date:</dt>
            <dd class="col-sm-7">${cert.issue_date ? formatDate(cert.issue_date) : 'N/A'}</dd>
            
            <dt class="col-sm-5">Expiry Date:</dt>
            <dd class="col-sm-7">${cert.expiry_date ? formatDate(cert.expiry_date) : 'N/A'}</dd>
            
            <dt class="col-sm-5">Status:</dt>
            <dd class="col-sm-7">
                <span class="badge bg-${getCertificateStatusClass(cert.approval_status)}">
                    ${cert.approval_status}
                </span>
            </dd>
            
            ${cert.approval_notes ? `
                <dt class="col-sm-5">Approval Notes:</dt>
                <dd class="col-sm-7">${escapeHtml(cert.approval_notes)}</dd>
            ` : ''}
            
            ${cert.rejection_reason ? `
                <dt class="col-sm-5">Rejection Reason:</dt>
                <dd class="col-sm-7 text-danger">${escapeHtml(cert.rejection_reason)}</dd>
            ` : ''}
        </dl>
    `;
    
    document.getElementById('certificateInfoDetails').innerHTML = infoHtml;
    
    // Set download button
    document.getElementById('downloadCertificateBtn').onclick = () => {
        window.open(`/mit/ajax/download_certificate.php?id=${cert.id}`, '_blank');
    };
    
    document.getElementById('downloadCertificateModalBtn').onclick = () => {
        window.open(`/mit/ajax/download_certificate.php?id=${cert.id}`, '_blank');
    };
}

// Manage certificate (approve/reject)
function manageCertificate(certificateId) {
    currentCertificateId = certificateId;
    
    // Load certificate details for approval modal
    const currentUserId = document.body.getAttribute('data-current-user-id');
    fetch(`/mit/ajax/upload_certificate.php?user_id=${currentUserId}&limit=1&offset=0`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const cert = data.certificates.find(c => c.id == certificateId);
                if (cert) {
                    document.getElementById('approvalCertificateId').value = certificateId;
                    document.getElementById('certificateDetailsPreview').innerHTML = `
                        <strong>${escapeHtml(cert.certificate_name)}</strong><br>
                        <small class="text-muted">
                            Type: ${cert.certificate_type}<br>
                            Organization: ${cert.issuing_organization || 'N/A'}<br>
                            Issue Date: ${cert.issue_date ? formatDate(cert.issue_date) : 'N/A'}
                        </small>
                    `;
                    var modal = new bootstrap.Modal(document.getElementById('certificateApprovalModal'));
                    modal.show();
                }
            }
        });
}

// Approve certificate
document.addEventListener('DOMContentLoaded', function() {
    const approveBtn = document.getElementById('approveCertificateBtn');
    if (approveBtn) {
        approveBtn.addEventListener('click', function() {
            const certificateId = document.getElementById('approvalCertificateId').value;
            const approvalNotes = document.getElementById('approvalNotes').value;
            
            fetch('/mit/ajax/manage_certificates.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=approve&certificate_id=${certificateId}&approval_notes=${encodeURIComponent(approvalNotes)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Certificate approved successfully!');
                    var modal = bootstrap.Modal.getInstance(document.getElementById('certificateApprovalModal'));
                    if (modal) {
                        modal.hide();
                    }
                    loadCertificates(); // Refresh certificate list
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while approving the certificate.');
            });
        });
    }
});

// Reject certificate
document.addEventListener('DOMContentLoaded', function() {
    const rejectBtn = document.getElementById('rejectCertificateBtn');
    if (rejectBtn) {
        rejectBtn.addEventListener('click', function() {
            const certificateId = document.getElementById('approvalCertificateId').value;
            const rejectionReason = document.getElementById('rejectionReason').value;
            
            if (!rejectionReason.trim()) {
                alert('Please enter a rejection reason.');
                return;
            }
            
            fetch('/mit/ajax/manage_certificates.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=reject&certificate_id=${certificateId}&rejection_reason=${encodeURIComponent(rejectionReason)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Certificate rejected successfully!');
                    var modal = bootstrap.Modal.getInstance(document.getElementById('certificateApprovalModal'));
                    if (modal) {
                        modal.hide();
                    }
                    loadCertificates(); // Refresh certificate list
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while rejecting the certificate.');
            });
        });
    }
});

// Helper functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

function getCertificateStatusClass(status) {
    switch(status) {
        case 'approved': return 'success';
        case 'pending': return 'warning';
        case 'rejected': return 'danger';
        default: return 'secondary';
    }
}

function canUploadCertificates() {
    return true; // All logged-in users can upload for themselves
}

function canManageCertificates() {
    const userRole = document.body.getAttribute('data-current-user-role');
    return ['super_admin', 'admin', 'manager'].includes(userRole);
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Auto-load certificates if container exists
    if (document.getElementById('certificatesContainer') || 
        document.querySelector('[data-certificates-container]')) {
        // Delay loading to ensure all components are ready
        setTimeout(() => {
            const currentUserId = document.body.getAttribute('data-current-user-id');
            loadCertificates(currentUserId);
        }, 100);
    }
});

// Make functions available globally
window.initCertificateUpload = initCertificateUpload;
window.loadCertificates = loadCertificates;
window.viewCertificate = viewCertificate;
window.manageCertificate = manageCertificate;