<?php
// pages/certificates/admin_manage.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';

$page_title = 'Certificate Management';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$current_user = getCurrentUser();
$user_role = $current_user['user_type'] ?? '';

// Check if user has admin privileges for certificate management
if (!in_array($user_role, ['super_admin', 'admin', 'manager'])) {
    $_SESSION['error'] = "You don't have permission to access certificate management.";
    header("Location: ../../dashboard.php");
    exit();
}

$pdo = getDBConnection();

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$user_filter = $_GET['user'] ?? '';
$search_filter = $_GET['search'] ?? '';

// Get users for filter dropdown
$users_stmt = $pdo->query("
    SELECT u.id, u.email, sp.full_name, u.user_type
    FROM users u
    LEFT JOIN staff_profiles sp ON u.id = sp.user_id
    WHERE u.user_type IN ('admin', 'manager', 'support_tech', 'client')
    ORDER BY u.email
");
$all_users = $users_stmt->fetchAll();

// Get certificate counts for stats
$count_stmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN approval_status = 'approved' THEN 1 END) as approved,
        COUNT(CASE WHEN approval_status = 'rejected' THEN 1 END) as rejected
    FROM certificates
");
$counts = $count_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Management | MSP Application</title>
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .stats-card {
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .certificate-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .certificate-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .status-badge {
            font-size: 0.8em;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .bulk-actions {
            background: #e9f7ef;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: none;
        }
    </style>
</head>
<body data-current-user-id="<?php echo $current_user['id'] ?? ''; ?>" data-current-user-role="<?php echo $current_user['user_type'] ?? ''; ?>">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="header d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="fas fa-certificate me-2"></i>Certificate Management</h1>
            <p class="text-muted">Manage user certificates and approvals</p>
        </div>
        <div>
            <span class="badge bg-primary">Role: <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></span>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card bg-primary text-white">
                <h3><?php echo $counts['total']; ?></h3>
                <p class="mb-0">Total Certificates</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card bg-warning text-dark">
                <h3><?php echo $counts['pending']; ?></h3>
                <p class="mb-0">Pending Review</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card bg-success text-white">
                <h3><?php echo $counts['approved']; ?></h3>
                <p class="mb-0">Approved</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card bg-danger text-white">
                <h3><?php echo $counts['rejected']; ?></h3>
                <p class="mb-0">Rejected</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" id="statusFilter">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">User</label>
                <select class="form-select" id="userFilter">
                    <option value="">All Users</option>
                    <?php foreach ($all_users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['id']); ?>" 
                                <?php echo $user_filter === $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'] ?? $user['email']); ?>
                            (<?php echo ucfirst(str_replace('_', ' ', $user['user_type'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" id="searchFilter" 
                       placeholder="Search certificates..." value="<?php echo htmlspecialchars($search_filter); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary w-100" id="applyFilters">
                    <i class="fas fa-filter me-1"></i>Apply
                </button>
            </div>
        </div>
    </div>

    <!-- Bulk Actions -->
    <div class="bulk-actions" id="bulkActions">
        <div class="row g-3">
            <div class="col-md-8">
                <span class="text-primary">Selected <span id="selectedCount">0</span> certificates</span>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-2">
                    <button class="btn btn-success btn-sm" id="bulkApproveBtn">
                        <i class="fas fa-check me-1"></i>Approve Selected
                    </button>
                    <button class="btn btn-danger btn-sm" id="bulkRejectBtn">
                        <i class="fas fa-times me-1"></i>Reject Selected
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Certificates Container -->
    <div id="certificatesList">
        <div class="text-center py-5">
            <i class="fas fa-spinner fa-spin fa-2x text-muted mb-3"></i>
            <p>Loading certificates...</p>
        </div>
    </div>
</div>

<!-- Certificate Detail Modal -->
<div class="modal fade" id="certificateDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Certificate Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="certificateDetailContent">
                    <!-- Content loaded via JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="approveSingleBtn">Approve</button>
                <button type="button" class="btn btn-danger" id="rejectSingleBtn">Reject</button>
            </div>
        </div>
    </div>
</div>

<!-- Rejection Reason Modal -->
<div class="modal fade" id="rejectionReasonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rejection Reason</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rejectionCertificateId">
                <textarea class="form-control" id="rejectionReason" rows="4" 
                          placeholder="Enter reason for rejection..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmRejectionBtn">Confirm Rejection</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/mit/js/main.js"></script>
<script src="../js/certificate_management.js"></script>

<script>
// Certificate Management Admin Functions
let selectedCertificates = [];
let currentDetailView = null;

// Load certificates based on filters
function loadCertificates() {
    const status = document.getElementById('statusFilter').value;
    const user = document.getElementById('userFilter').value;
    const search = document.getElementById('searchFilter').value;
    
    // Show loading state
    document.getElementById('certificatesList').innerHTML = `
        <div class="text-center py-5">
            <i class="fas fa-spinner fa-spin fa-2x text-muted mb-3"></i>
            <p>Loading certificates...</p>
        </div>
    `;
    
    // Make API call to get certificates
    const params = new URLSearchParams({
        status: status,
        user_id: user,
        search: search,
        limit: 50
    });
    
    fetch(`/mit/ajax/manage_certificates.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayCertificates(data.certificates);
            } else {
                document.getElementById('certificatesList').innerHTML = `
                    <div class="alert alert-danger">
                        Error loading certificates: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('certificatesList').innerHTML = `
                <div class="alert alert-danger">
                    Error loading certificates: ${error.message}
                </div>
            `;
        });
}

// Display certificates in the list
function displayCertificates(certificates) {
    if (certificates.length === 0) {
        document.getElementById('certificatesList').innerHTML = `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No certificates found matching the criteria.
            </div>
        `;
        return;
    }
    
    const html = certificates.map(cert => `
        <div class="card mb-3 certificate-card">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-1">
                        <input type="checkbox" class="form-check-input" value="${cert.id}" onchange="toggleCertificateSelection(this)">
                    </div>
                    <div class="col-md-3">
                        <strong>${escapeHtml(cert.certificate_name)}</strong>
                        <div class="small text-muted">${escapeHtml(cert.certificate_type)}</div>
                    </div>
                    <div class="col-md-2">
                        <div class="small">${escapeHtml(cert.user_name || cert.user_email)}</div>
                        <div class="small text-muted">${escapeHtml(cert.issuing_organization || 'N/A')}</div>
                    </div>
                    <div class="col-md-2">
                        <div class="small">${cert.issue_date || 'N/A'}</div>
                        <div class="small">${cert.expiry_date || 'N/A'}</div>
                    </div>
                    <div class="col-md-2">
                        <span class="badge bg-${getStatusClass(cert.approval_status)} status-badge">
                            ${cert.approval_status}
                        </span>
                    </div>
                    <div class="col-md-2 text-end">
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="viewCertificateDetail(${cert.id})">
                            <i class="fas fa-eye"></i>
                        </button>
                        <a href="/mit/ajax/download_certificate.php?id=${cert.id}&download=1" class="btn btn-sm btn-outline-success me-1">
                            <i class="fas fa-download"></i>
                        </a>
                        ${canManage(cert.approval_status) ? `
                            <button class="btn btn-sm btn-outline-success me-1" onclick="approveCertificate(${cert.id})">
                                <i class="fas fa-check"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="rejectCertificate(${cert.id})">
                                <i class="fas fa-times"></i>
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>
    `).join('');
    
    document.getElementById('certificatesList').innerHTML = html;
}

// Toggle certificate selection for bulk actions
function toggleCertificateSelection(checkbox) {
    const certId = parseInt(checkbox.value);
    
    if (checkbox.checked) {
        if (!selectedCertificates.includes(certId)) {
            selectedCertificates.push(certId);
        }
    } else {
        selectedCertificates = selectedCertificates.filter(id => id !== certId);
    }
    
    updateBulkActions();
}

// Update bulk actions UI
function updateBulkActions() {
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    
    selectedCount.textContent = selectedCertificates.length;
    
    if (selectedCertificates.length > 0) {
        bulkActions.style.display = 'block';
    } else {
        bulkActions.style.display = 'none';
    }
}

// View certificate details
function viewCertificateDetail(certId) {
    currentDetailView = certId;
    
    // Fetch specific certificate details using the correct endpoint
    fetch(`/mit/ajax/upload_certificate.php?id=${certId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.certificate) {
                const cert = data.certificate;
                
                // Build the preview URL for the certificate file (using inline disposition)
                const previewUrl = `/mit/ajax/download_certificate.php?id=${cert.id}&preview=1`;
                // Build the download URL for the certificate file (using attachment disposition)
                const downloadUrl = `/mit/ajax/download_certificate.php?id=${cert.id}&download=1`;
                
                // Determine if the file can be previewed inline
                const fileExtension = cert.file_name ? cert.file_name.split('.').pop().toLowerCase() : '';
                const mimeType = cert.mime_type || '';
                
                // Create preview content based on file type
                let previewContent = '';
                if (mimeType.startsWith('image/') || fileExtension === 'pdf') {
                    if (mimeType.startsWith('image/')) {
                        previewContent = `<img src="${previewUrl}" alt="${escapeHtml(cert.certificate_name)}" style="max-width: 100%; max-height: 300px; object-fit: contain;" />`;
                    } else if (fileExtension === 'pdf') {
                        previewContent = `<iframe src="${previewUrl}" style="width: 100%; height: 300px; border: none;"></iframe>`;
                    }
                } else {
                    previewContent = `
                        <div class="text-center">
                            <i class="fas fa-file-alt fa-3x text-muted mb-2"></i>
                            <p class="text-muted small">Preview not available for this file type</p>
                            <p class="text-muted small">${escapeHtml(cert.file_name)}</p>
                        </div>
                    `;
                }
                
                const html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Certificate Information</h6>
                            <dl class="row small">
                                <dt class="col-sm-5">Name:</dt>
                                <dd class="col-sm-7">${escapeHtml(cert.certificate_name)}</dd>
                                
                                <dt class="col-sm-5">Type:</dt>
                                <dd class="col-sm-7">${escapeHtml(cert.certificate_type)}</dd>
                                
                                <dt class="col-sm-5">Organization:</dt>
                                <dd class="col-sm-7">${escapeHtml(cert.issuing_organization || 'N/A')}</dd>
                                
                                <dt class="col-sm-5">Issue Date:</dt>
                                <dd class="col-sm-7">${cert.issue_date || 'N/A'}</dd>
                                
                                <dt class="col-sm-5">Expiry Date:</dt>
                                <dd class="col-sm-7">${cert.expiry_date || 'N/A'}</dd>
                                
                                <dt class="col-sm-5">Number:</dt>
                                <dd class="col-sm-7">${escapeHtml(cert.certificate_number || 'N/A')}</dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <h6>User Information</h6>
                            <dl class="row small">
                                <dt class="col-sm-5">User:</dt>
                                <dd class="col-sm-7">${escapeHtml(cert.user_name || cert.user_email)}</dd>
                                
                                <dt class="col-sm-5">Email:</dt>
                                <dd class="col-sm-7">${escapeHtml(cert.user_email)}</dd>
                                
                                <dt class="col-sm-5">Status:</dt>
                                <dd class="col-sm-7">
                                    <span class="badge bg-${getStatusClass(cert.approval_status)}">
                                        ${cert.approval_status}
                                    </span>
                                </dd>
                                
                                <dt class="col-sm-5">Uploaded:</dt>
                                <dd class="col-sm-7">${new Date(cert.created_at).toLocaleDateString()}</dd>
                            </dl>
                            
                            ${cert.approval_status !== 'pending' ? `
                                <h6 class="mt-3">Approval Information</h6>
                                <dl class="row small">
                                    <dt class="col-sm-5">Approved By:</dt>
                                    <dd class="col-sm-7">${escapeHtml(cert.approved_by_name || 'N/A')}</dd>
                                    
                                    ${cert.approval_notes ? `
                                        <dt class="col-sm-5">Notes:</dt>
                                        <dd class="col-sm-7">${escapeHtml(cert.approval_notes)}</dd>
                                    ` : ''}
                                    
                                    ${cert.rejection_reason ? `
                                        <dt class="col-sm-5">Rejection Reason:</dt>
                                        <dd class="col-sm-7 text-danger">${escapeHtml(cert.rejection_reason)}</dd>
                                    ` : ''}
                                </dl>
                            ` : ''}
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-8">
                            <h6>File Preview</h6>
                            <div class="border rounded p-3 bg-light text-center" style="min-height: 320px;">
                                ${previewContent}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6>Actions</h6>
                            <div class="d-grid gap-2">
                                <a href="${downloadUrl}" class="btn btn-primary" target="_blank">
                                    <i class="fas fa-download me-1"></i>Download Certificate
                                </a>
                                ${canManage(cert.approval_status) ? `
                                    <button class="btn btn-success" onclick="approveCertificate(${cert.id})">
                                        <i class="fas fa-check me-1"></i>Approve
                                    </button>
                                    <button class="btn btn-danger" onclick="rejectCertificate(${cert.id})">
                                        <i class="fas fa-times me-1"></i>Reject
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('certificateDetailContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('certificateDetailModal')).show();
            } else {
                console.error('Error loading certificate:', data.message);
                alert('Error loading certificate details.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while loading certificate details.');
        });
}

// Approve single certificate
function approveCertificate(certId) {
    if (confirm('Are you sure you want to approve this certificate?')) {
        fetch('/mit/ajax/manage_certificates.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=approve&certificate_id=${certId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Certificate approved successfully!');
                loadCertificates(); // Refresh the list
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}

// Reject single certificate
function rejectCertificate(certId) {
    document.getElementById('rejectionCertificateId').value = certId;
    new bootstrap.Modal(document.getElementById('rejectionReasonModal')).show();
}

// Confirm rejection
document.getElementById('confirmRejectionBtn').addEventListener('click', function() {
    const certId = document.getElementById('rejectionCertificateId').value;
    const reason = document.getElementById('rejectionReason').value.trim();
    
    if (!reason) {
        alert('Please enter a rejection reason.');
        return;
    }
    
    fetch('/mit/ajax/manage_certificates.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=reject&certificate_id=${certId}&rejection_reason=${encodeURIComponent(reason)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Certificate rejected successfully!');
            bootstrap.Modal.getInstance(document.getElementById('rejectionReasonModal')).hide();
            document.getElementById('rejectionReason').value = '';
            loadCertificates(); // Refresh the list
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
});

// Bulk approve certificates
document.getElementById('bulkApproveBtn').addEventListener('click', function() {
    if (selectedCertificates.length === 0) {
        alert('Please select at least one certificate.');
        return;
    }
    
    if (confirm(`Are you sure you want to approve ${selectedCertificates.length} certificate(s)?`)) {
        fetch('/mit/ajax/manage_certificates.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=bulk_approve&certificate_ids=${JSON.stringify(selectedCertificates)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                selectedCertificates = [];
                updateBulkActions();
                loadCertificates(); // Refresh the list
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
});

// Bulk reject certificates
document.getElementById('bulkRejectBtn').addEventListener('click', function() {
    if (selectedCertificates.length === 0) {
        alert('Please select at least one certificate.');
        return;
    }
    
    const reason = prompt('Enter reason for rejecting the selected certificates:');
    if (!reason) {
        return;
    }
    
    fetch('/mit/ajax/manage_certificates.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=bulk_reject&certificate_ids=${JSON.stringify(selectedCertificates)}&rejection_reason=${encodeURIComponent(reason)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            selectedCertificates = [];
            updateBulkActions();
            loadCertificates(); // Refresh the list
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
});

// Apply filters
document.getElementById('applyFilters').addEventListener('click', loadCertificates);

// Helper functions
function getStatusClass(status) {
    switch(status) {
        case 'approved': return 'success';
        case 'pending': return 'warning';
        case 'rejected': return 'danger';
        default: return 'secondary';
    }
}

function canManage(status) {
    const userRole = document.body.getAttribute('data-current-user-role');
    return ['super_admin', 'admin', 'manager'].includes(userRole) && status === 'pending';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Event listeners for filter changes
document.getElementById('statusFilter').addEventListener('change', loadCertificates);
document.getElementById('userFilter').addEventListener('change', loadCertificates);
document.getElementById('searchFilter').addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        loadCertificates();
    }
});

// Initial load
document.addEventListener('DOMContentLoaded', function() {
    loadCertificates();
});
</script>
</body>
</html>