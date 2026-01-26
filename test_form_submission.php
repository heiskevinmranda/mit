<?php
// test_form_submission.php - Test form data collection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>Received POST Data</h2>";
    echo "<pre>" . htmlspecialchars(print_r($_POST, true)) . "</pre>";
    
    // Also check raw input
    $raw_input = file_get_contents('php://input');
    echo "<h3>Raw Input:</h3>";
    echo "<pre>" . htmlspecialchars($raw_input) . "</pre>";
    
    // Try to decode JSON if present
    if (!empty($raw_input)) {
        $json_data = json_decode($raw_input, true);
        if ($json_data !== null) {
            echo "<h3>JSON Decoded:</h3>";
            echo "<pre>" . htmlspecialchars(print_r($json_data, true)) . "</pre>";
        } else {
            echo "<p>Invalid JSON data</p>";
        }
    }
    
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Form Submission</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Test Bulk Task Form Submission</h1>
        
        <form id="testForm">
            <div class="mb-3">
                <label class="form-label">Assigned To</label>
                <select class="form-select" name="assigned_to" required>
                    <option value="">Select user</option>
                    <?php
                    require_once 'config/database.php';
                    try {
                        $pdo = getDBConnection();
                        $stmt = $pdo->query("SELECT id, email FROM users WHERE is_active = true ORDER BY email LIMIT 10");
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($users as $user) {
                            echo '<option value="' . htmlspecialchars($user['id']) . '">' . htmlspecialchars($user['email']) . '</option>';
                        }
                    } catch (Exception $e) {
                        echo '<option value="">Error loading users</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Default Priority</label>
                <select class="form-select" name="default_priority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            
            <div id="taskListContainer">
                <!-- Task items will be added here -->
            </div>
            
            <button type="button" class="btn btn-secondary mb-3" onclick="addTask()">Add Task</button>
            <br>
            <button type="submit" class="btn btn-primary">Submit Form</button>
        </form>
        
        <div id="results" class="mt-4"></div>
    </div>

    <script>
        let taskCounter = 1;
        
        function addTask() {
            const container = document.getElementById('taskListContainer');
            const taskDiv = document.createElement('div');
            taskDiv.className = 'task-item mb-3 p-3 border rounded';
            taskDiv.dataset.taskId = taskCounter;
            
            taskDiv.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Task #${taskCounter}</h6>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.parentElement.parentElement.remove()">
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <input type="text" class="form-control task-title" name="tasks[${taskCounter}][task_title]" 
                               placeholder="Task Title *" required>
                    </div>
                    <div class="col-md-3 mb-2">
                        <select class="form-select task-priority" name="tasks[${taskCounter}][priority]">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <textarea class="form-control task-description" name="tasks[${taskCounter}][task_description]" 
                              rows="2" placeholder="Description (optional)"></textarea>
                </div>
            `;
            
            container.appendChild(taskDiv);
            taskCounter++;
        }
        
        // Add initial task
        addTask();
        
        // Handle form submission
        document.getElementById('testForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            const submitButton = e.submitter;
            
            // Collect all tasks manually (same as dashboard)
            const tasks = [];
            const assignedTo = formData.get('assigned_to');
            
            const taskItems = document.querySelectorAll('.task-item');
            taskItems.forEach(item => {
                const title = item.querySelector('.task-title').value.trim();
                const description = item.querySelector('.task-description').value.trim();
                const priority = item.querySelector('.task-priority').value;
                
                if (title) {
                    tasks.push({
                        task_title: title,
                        task_description: description,
                        priority: priority,
                        assigned_to: assignedTo
                    });
                }
            });
            
            console.log('Collected tasks:', tasks);
            console.log('Assigned to:', assignedTo);
            
            // Display collected data
            document.getElementById('results').innerHTML = `
                <h3>Collected Data:</h3>
                <p><strong>Assigned To:</strong> ${assignedTo}</p>
                <p><strong>Tasks Count:</strong> ${tasks.length}</p>
                <pre>${JSON.stringify(tasks, null, 2)}</pre>
            `;
            
            // Send AJAX request (same as dashboard)
            fetch('test_form_submission.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ tasks: tasks })
            })
            .then(response => response.text())
            .then(data => {
                document.getElementById('results').innerHTML += `
                    <h3>Server Response:</h3>
                    <div>${data}</div>
                `;
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('results').innerHTML += `
                    <h3>Error:</h3>
                    <p>${error.message}</p>
                `;
            });
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>