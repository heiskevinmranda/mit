<?php
// index.php - Updated with dual login options
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP Application | Managed Services Provider</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .landing-container {
            max-width: 1200px;
            width: 100%;
        }
        
        .landing-header {
            text-align: center;
            margin-bottom: 50px;
        }
        
        .landing-header h1 {
            font-size: 3.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }
        
        .landing-header p {
            font-size: 1.2rem;
            color: #666;
            max-width: 700px;
            margin: 0 auto;
            line-height: 1.6;
        }
        
        .login-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-bottom: 50px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .login-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            border-color: var(--primary);
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
        }
        
        .client-card::before {
            background: linear-gradient(90deg, var(--success) 0%, #20c997 100%);
        }
        
        .staff-card::before {
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .card-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 32px;
            color: white;
        }
        
        .client-card .card-icon {
            background: linear-gradient(135deg, var(--success) 0%, #20c997 100%);
        }
        
        .staff-card .card-icon {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .login-card h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--dark);
        }
        
        .login-card p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 25px;
            font-size: 0.95rem;
        }
        
        .badge-role {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .client-card .badge-role {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }
        
        .staff-card .badge-role {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }
        
        .btn-login {
            padding: 14px 30px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            border: 2px solid transparent;
            width: 100%;
            text-align: center;
        }
        
        .client-card .btn-login {
            background: linear-gradient(135deg, var(--success) 0%, #20c997 100%);
            color: white;
        }
        
        .client-card .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .staff-card .btn-login {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }
        
        .staff-card .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
            color: white;
        }
        
        .features {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-top: 30px;
        }
        
        .features h2 {
            text-align: center;
            margin-bottom: 40px;
            color: var(--dark);
            font-weight: 700;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
        }
        
        .feature-item {
            text-align: center;
            padding: 20px;
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 24px;
        }
        
        .feature-1 .feature-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .feature-2 .feature-icon { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .feature-3 .feature-icon { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); }
        .feature-4 .feature-icon { background: linear-gradient(135deg, #17a2b8 0%, #6c757d 100%); }
        
        .feature-item h4 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .feature-item p {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .footer {
            text-align: center;
            margin-top: 50px;
            color: #666;
            font-size: 0.9rem;
        }
        
        .footer a {
            color: var(--primary);
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .landing-header h1 {
                font-size: 2.5rem;
            }
            
            .login-cards {
                grid-template-columns: 1fr;
            }
            
            .login-card {
                padding: 30px;
            }
            
            .features {
                padding: 30px 20px;
            }
        }
        
        .hero-section {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 40px;
            text-align: center;
        }
        
        .hero-section h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--dark);
        }
        
        .hero-section p {
            font-size: 1.1rem;
            color: #666;
            max-width: 800px;
            margin: 0 auto 30px;
            line-height: 1.6;
        }
        
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            border-left: 5px solid var(--primary);
        }
        
        .demo-credentials h5 {
            color: var(--dark);
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .credentials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .credential-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        
        .credential-item h6 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .credential-item p {
            font-weight: 600;
            color: var(--dark);
            margin: 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="landing-container">
        <!-- Header -->
        <div class="landing-header">
            <h1>MSP Application</h1>
            <p>Managed Services Provider Platform for comprehensive IT service management, client support, and asset tracking.</p>
        </div>
        
        <!-- Hero Section -->
        <div class="hero-section">
            <h2>Welcome to Your IT Service Management Hub</h2>
            <p>Choose your login portal below based on your role. Our platform provides seamless collaboration between clients and service providers.</p>
        </div>
        
        <!-- Login Cards -->
        <div class="login-cards">
            <!-- Client Login Card -->
            <div class="login-card client-card">
                <div class="card-icon">
                    <i class="fas fa-building"></i>
                </div>
                <span class="badge-role">For Businesses</span>
                <h3>Client Portal</h3>
                <p>Access your support tickets, track assets, view contracts, and communicate with our support team. Manage all your IT service needs in one place.</p>
                
                <div class="mt-4 mb-4">
                    <h6 class="text-muted mb-2">What you can do:</h6>
                    <ul style="text-align: left; color: #666; font-size: 0.9rem; padding-left: 20px;">
                        <li>Submit and track support tickets</li>
                        <li>View your IT assets inventory</li>
                        <li>Access service contracts and SLAs</li>
                        <li>Check ticket resolution progress</li>
                        <li>Download reports and invoices</li>
                    </ul>
                </div>
                
                <a href="/mit/client-login.php" class="btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i> Login to Client Portal
                </a>
            </div>
            
            <!-- Staff/Management Login Card -->
            <div class="login-card staff-card">
                <div class="card-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
                <span class="badge-role">For Service Team</span>
                <h3>Management System</h3>
                <p>Access the comprehensive MSP management system for ticket management, client administration, asset tracking, reporting, and team coordination.</p>
                
                <div class="mt-4 mb-4">
                    <h6 class="text-muted mb-2">System features include:</h6>
                    <ul style="text-align: left; color: #666; font-size: 0.9rem; padding-left: 20px;">
                        <li>Complete ticket management system</li>
                        <li>Client and asset database</li>
                        <li>Staff management and scheduling</li>
                        <li>Advanced analytics and reporting</li>
                        <li>Contract and SLA management</li>
                    </ul>
                </div>
                
                <a href="/mit/login" class="btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i> Login to Management System
                </a>
            </div>
        </div>
        
        <!-- Demo Credentials -->
        <div class="demo-credentials">
            <h5><i class="fas fa-key me-2"></i>Demo Credentials</h5>
            <div class="credentials-grid">
                <div class="credential-item">
                    <h6>Client Login (Default):</h6>
                    <p>Email: Your company email</p>
                    <p>Password: xxxxxxxx</p>
                </div>
                <div class="credential-item">
                    <h6>Staff Login (Example):</h6>
                    <p>Email: admin@msp.com</p>
                    <p>Password: [Contact Admin]</p>
                </div>
            </div>
            <small class="text-muted mt-3 d-block">Note: Clients should change their password after first login.</small>
        </div>
        
        <!-- Features -->
        <div class="features">
            <h2>Why Choose Our MSP Platform</h2>
            <div class="features-grid">
                <div class="feature-item feature-1">
                    <div class="feature-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h4>24/7 Support</h4>
                    <p>Round-the-clock technical support with guaranteed response times and dedicated account managers.</p>
                </div>
                
                <div class="feature-item feature-2">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h4>Secure & Compliant</h4>
                    <p>Enterprise-grade security with data encryption, role-based access, and compliance certifications.</p>
                </div>
                
                <div class="feature-item feature-3">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h4>Real-time Analytics</h4>
                    <p>Comprehensive dashboards and reporting tools for performance monitoring and decision making.</p>
                </div>
                
                <div class="feature-item feature-4">
                    <div class="feature-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h4>Mobile Ready</h4>
                    <p>Fully responsive design accessible from any device - desktop, tablet, or smartphone.</p>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> MSP Application. All rights reserved.</p>
            <p>
                <a href="#">Privacy Policy</a> • 
                <a href="#">Terms of Service</a> • 
                <a href="#">Support Center</a> • 
                <a href="#">Contact Us</a>
            </p>
            <p class="mt-3">
                <small>Version 2.0 | Built with <i class="fas fa-heart text-danger"></i> for MSPs worldwide</small>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add animation on scroll
        document.addEventListener('DOMContentLoaded', function() {
            // Animate cards on load
            const cards = document.querySelectorAll('.login-card');
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 200);
            });
            
            // Add hover effects
            const loginButtons = document.querySelectorAll('.btn-login');
            loginButtons.forEach(button => {
                button.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px) scale(1.02)';
                });
                
                button.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
            
            // Auto-rotate feature highlights
            let currentFeature = 0;
            const featureItems = document.querySelectorAll('.feature-item');
            
            function highlightFeature() {
                featureItems.forEach(item => item.style.opacity = '0.6');
                featureItems[currentFeature].style.opacity = '1';
                featureItems[currentFeature].style.transform = 'scale(1.05)';
                
                currentFeature = (currentFeature + 1) % featureItems.length;
            }
            
            // Start rotation if there are features
            if (featureItems.length > 0) {
                setInterval(highlightFeature, 3000);
                // Initial highlight
                setTimeout(() => highlightFeature(), 500);
            }
        });
    </script>
</body>
</html>