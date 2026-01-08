    </div> <!-- Close main-content -->
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/main.js"></script>
    <script>
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert:not(.flash-message)').forEach(alert => {
                alert.remove();
            });
        }, 5000);
    </script>
</body>
</html>
<?php
// Clean up output buffer if it was started
if (ob_get_level()) {
    ob_end_flush();
}
?>