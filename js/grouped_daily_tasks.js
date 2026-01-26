// grouped_daily_tasks.js - Enhanced daily tasks display with user grouping

// Function to load and display grouped daily tasks
function loadGroupedDailyTasks() {
    // Show the modal first
    const modal = new bootstrap.Modal(document.getElementById('manageDailyTasksModal'));
    modal.show();
    
    // Load grouped tasks via AJAX
    fetch('ajax/get_grouped_daily_tasks.php')
        .then(response => response.json())
        .then(data => {
            const tasksContainer = document.getElementById('dailyTasksManagement');
            
            if (data.success && data.grouped) {
                // Display grouped tasks
                displayGroupedTasks(tasksContainer, data.data.users);
            } else if (data.success && !data.grouped) {
                // Fallback to flat display if grouping not available
                displayFlatTasks(tasksContainer, data.data.tasks);
            } else {
                tasksContainer.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No tasks requiring follow-up</h5>
                        <p class="text-muted">All tasks are completed or no pending tasks assigned to you.</p>
                    </div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('dailyTasksManagement').innerHTML = `
                <div class="alert alert-danger">
                    Error loading tasks: ${error.message}
                </div>`;
        });
}

// Function to display tasks grouped by user
function displayGroupedTasks(container, groupedUsers) {
    let html = '<div class="grouped-tasks-container">';
    
    // Iterate through each user group
    Object.keys(groupedUsers).forEach(userKey => {
        const userGroup = groupedUsers[userKey];
        const userTasks = userGroup.tasks;
        
        if (userTasks.length > 0) {
            html += `
                <div class="user-task-group mb-4">
                    <div class="user-header bg-light p-3 rounded mb-3">
                        <h5 class="mb-0">
                            <i class="fas fa-user me-2"></i>
                            ${userGroup.user_name}
                            <span class="badge bg-primary ms-2">${userTasks.length} task${userTasks.length !== 1 ? 's' : ''}</span>
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th width="30%">Task Title</th>
                                    <th width="25%">Status</th>
                                    <th width="15%">Priority</th>
                                    <th width="20%">Created At</th>
                                    <th width="10%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>`;
            
            // Add each task for this user
            userTasks.forEach(task => {
                const priorityClass = getPriorityBadgeClass(task.priority);
                const statusClass = getStatusBadgeClass(task.task_status);
                const createdAt = formatDate(task.created_at);
                
                html += `
                    <tr>
                        <td>
                            <strong>${escapeHtml(task.task_title)}</strong>
                            ${task.task_description ? `<div class="small text-muted mt-1">${escapeHtml(task.task_description)}</div>` : ''}
                        </td>
                        <td><span class="badge ${statusClass}">${formatStatus(task.task_status)}</span></td>
                        <td><span class="badge ${priorityClass}">${formatPriority(task.priority)}</span></td>
                        <td>${createdAt}</td>
                        <td>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary" 
                                        onclick="editTask(${task.id}, '${escapeHtml(task.task_title)}', '${escapeHtml(task.task_description || '')}', '${escapeHtml(task.assigned_to_name || '')}', '${task.task_status}', '${task.priority}')"
                                        title="Edit Task">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger" 
                                        onclick="deleteTask(${task.id})"
                                        title="Delete Task">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>`;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>`;
        }
    });
    
    html += '</div>';
    container.innerHTML = html;
}

// Function to display tasks in flat format (fallback)
function displayFlatTasks(container, tasks) {
    if (tasks.length > 0) {
        let html = `
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Task Title</th>
                            <th>Description</th>
                            <th>Assigned To</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>`;
        
        tasks.forEach(task => {
            const priorityClass = getPriorityBadgeClass(task.priority);
            const statusClass = getStatusBadgeClass(task.task_status);
            const createdAt = formatDate(task.created_at);
            
            html += `
                <tr>
                    <td><strong>${escapeHtml(task.task_title)}</strong></td>
                    <td>${escapeHtml(task.task_description || 'No description')}</td>
                    <td>${escapeHtml(task.assigned_to_name || 'Not assigned')}</td>
                    <td><span class="badge ${priorityClass}">${formatPriority(task.priority)}</span></td>
                    <td><span class="badge ${statusClass}">${formatStatus(task.task_status)}</span></td>
                    <td>${createdAt}</td>
                    <td>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary" 
                                    onclick="editTask(${task.id}, '${escapeHtml(task.task_title)}', '${escapeHtml(task.task_description || '')}', '${escapeHtml(task.assigned_to_name || '')}', '${task.task_status}', '${task.priority}')"
                                    title="Edit Task">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger" 
                                    onclick="deleteTask(${task.id})"
                                    title="Delete Task">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
        });
        
        html += `
                    </tbody>
                </table>
            </div>`;
        
        container.innerHTML = html;
    } else {
        container.innerHTML = `
            <div class="text-center py-5">
                <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No tasks for today</h5>
                <p class="text-muted">Click "Add Daily Task" to create your first task.</p>
            </div>`;
    }
}

// Helper functions for formatting
function getPriorityBadgeClass(priority) {
    const classes = {
        'urgent': 'bg-danger',
        'high': 'bg-warning text-dark',
        'medium': 'bg-info text-dark',
        'low': 'bg-secondary'
    };
    return classes[priority] || 'bg-secondary';
}

function getStatusBadgeClass(status) {
    const classes = {
        'pending': 'bg-secondary',
        'in_progress': 'bg-warning text-dark',
        'completed': 'bg-success',
        'cancelled': 'bg-danger'
    };
    return classes[status] || 'bg-secondary';
}

function formatPriority(priority) {
    const labels = {
        'low': 'Low',
        'medium': 'Medium',
        'high': 'High',
        'urgent': 'Urgent'
    };
    return labels[priority] || priority;
}

function formatStatus(status) {
    const labels = {
        'pending': 'Pending',
        'in_progress': 'In Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled'
    };
    return labels[status] || status;
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Existing functions for edit and delete (keeping compatibility)
function editTask(taskId, title, description, assignedTo, status, priority) {
    // Set form values
    document.getElementById('editTaskId').value = taskId;
    document.getElementById('editTaskTitle').value = title;
    document.getElementById('editTaskDescription').value = description;
    document.getElementById('editAssignedToName').value = assignedTo;
    document.getElementById('editTaskStatus').value = status;
    document.getElementById('editTaskPriority').value = priority;
    
    // Hide the management modal and show edit modal
    const manageModal = bootstrap.Modal.getInstance(document.getElementById('manageDailyTasksModal'));
    manageModal.hide();
    
    const editModal = new bootstrap.Modal(document.getElementById('editTaskModal'));
    editModal.show();
}

function deleteTask(taskId) {
    if (confirm('Are you sure you want to delete this task?')) {
        const formData = new FormData();
        formData.append('task_id', taskId);
        
        fetch('ajax/delete_daily_task.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Task deleted successfully!');
                // Reload the task list
                loadGroupedDailyTasks();
            } else {
                alert('Error: ' + (data.message || 'Failed to delete task'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while deleting the task.');
        });
    }
}

// Handle edit task form submission
document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.getElementById('editTaskForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('ajax/edit_daily_task.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Task updated successfully!');
                    // Close edit modal
                    const editModal = bootstrap.Modal.getInstance(document.getElementById('editTaskModal'));
                    editModal.hide();
                    // Reload the management view
                    loadGroupedDailyTasks();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update task'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the task.');
            });
        });
    }
});