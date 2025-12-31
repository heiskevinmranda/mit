    </div> <!-- Close main-content -->
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../js/main.js"></script>
    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert:not(.flash-message)').forEach(alert => {
                alert.remove();
            });
        }, 5000);
        
        // Confirm delete actions
        document.querySelectorAll('a[data-confirm]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!confirm(this.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>