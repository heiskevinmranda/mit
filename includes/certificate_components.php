<!-- Certificate Upload Modal -->
<div class="modal fade" id="certificateUploadModal" tabindex="-1" aria-labelledby="certificateUploadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="certificateUploadModalLabel">
                    <i class="fas fa-certificate me-2"></i>Upload Certificate
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="certificateUploadForm" enctype="multipart/form-data">
                    <input type="hidden" id="certificateUserId" name="user_id" value="">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="certificateName" class="form-label">Certificate Name *</label>
                            <input type="text" class="form-control" id="certificateName" name="certificate_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="certificateType" class="form-label">Certificate Type *</label>
                            <select class="form-select" id="certificateType" name="certificate_type" required>
                                <option value="">Select Type</option>
                                <option value="technical">Technical</option>
                                <option value="academic">Academic</option>
                                <option value="professional">Professional</option>
                                <option value="security">Security</option>
                                <option value="compliance">Compliance</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="issuingOrganization" class="form-label">Issuing Organization</label>
                            <input type="text" class="form-control" id="issuingOrganization" name="issuing_organization">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="certificateNumber" class="form-label">Certificate Number</label>
                            <input type="text" class="form-control" id="certificateNumber" name="certificate_number">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="issueDate" class="form-label">Issue Date</label>
                            <input type="date" class="form-control" id="issueDate" name="issue_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="expiryDate" class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" id="expiryDate" name="expiry_date">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="certificateFile" class="form-label">Certificate File *</label>
                        <input type="file" class="form-control" id="certificateFile" name="certificate_file" accept=".pdf,.jpg,.jpeg,.png,.gif" required>
                        <div class="form-text">Supported formats: PDF, JPG, PNG, GIF. Maximum file size: 5MB</div>
                    </div>

                    <div class="mb-3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> 
                            <span id="certificateUploadNote">
                                You can upload certificates for your own profile. Admins and managers can upload certificates for other users.
                            </span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="uploadCertificateBtn">
                    <i class="fas fa-upload me-1"></i>Upload Certificate
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Certificate Approval Modal -->
<div class="modal fade" id="certificateApprovalModal" tabindex="-1" aria-labelledby="certificateApprovalModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="certificateApprovalModalLabel">
                    <i class="fas fa-certificate me-2"></i>Certificate Approval
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="approvalCertificateId" value="">
                
                <div class="mb-3">
                    <label class="form-label">Certificate Details</label>
                    <div id="certificateDetailsPreview" class="border rounded p-3 bg-light">
                        Loading...
                    </div>
                </div>

                <div class="mb-3" id="approvalSection">
                    <label for="approvalNotes" class="form-label">Approval Notes</label>
                    <textarea class="form-control" id="approvalNotes" rows="3" placeholder="Enter approval notes (optional)"></textarea>
                </div>

                <div class="mb-3" id="rejectionSection" style="display: none;">
                    <label for="rejectionReason" class="form-label">Rejection Reason *</label>
                    <textarea class="form-control" id="rejectionReason" rows="3" placeholder="Enter reason for rejection" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success" id="approveCertificateBtn">
                    <i class="fas fa-check me-1"></i>Approve
                </button>
                <button type="button" class="btn btn-danger" id="rejectCertificateBtn">
                    <i class="fas fa-times me-1"></i>Reject
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Certificate View Modal -->
<div class="modal fade" id="certificateViewModal" tabindex="-1" aria-labelledby="certificateViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="certificateViewModalLabel">
                    <i class="fas fa-certificate me-2"></i>Certificate Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div id="certificatePreviewContainer" class="border rounded p-3 text-center bg-light" style="min-height: 400px;">
                            <div class="d-flex align-items-center justify-content-center h-100">
                                <div>
                                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">Certificate preview will appear here</p>
                                    <button class="btn btn-primary" id="downloadCertificateBtn">
                                        <i class="fas fa-download me-1"></i>Download Certificate
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Certificate Information</h6>
                            </div>
                            <div class="card-body">
                                <div id="certificateInfoDetails">
                                    Loading certificate information...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
                <button type="button" class="btn btn-primary" id="downloadCertificateModalBtn">
                    <i class="fas fa-download me-1"></i>Download
                </button>
            </div>
        </div>
    </div>
</div>