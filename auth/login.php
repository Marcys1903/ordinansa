<?php
// Start session
session_start();

// Include database configuration
require_once '../config/database.php';

// Create database connection
$database = new Database();
$conn = $database->getConnection();

// Check if already logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    redirectBasedOnRole($_SESSION['role']);
    exit();
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    // Basic validation
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email address and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid government email address.';
    } elseif (!strpos($email, '@qc.gov.ph')) {
        $error = 'Please use your official Quezon City government email.';
    } else {
        // Check user credentials in database
        $query = "SELECT id, email, password, first_name, last_name, role, department FROM users 
                  WHERE email = :email AND is_active = 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':email', $email);
        
        if ($stmt->execute()) {
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch();
                
                // Verify password (using password_verify for hashed passwords)
                // Note: For testing with plain text passwords, use direct comparison
                // In production, always use password_hash() and password_verify()
                
                // For testing with plain text (remove in production)
                $hashed_password = $user['password'];
                
                // Check if password matches (for testing with provided plain text passwords)
                $test_passwords = [
                    'superadmin123',
                    'admin123', 
                    'councilor123'
                ];
                
                $valid_password = false;
                
                // First try password_verify (for hashed passwords)
                if (password_verify($password, $hashed_password)) {
                    $valid_password = true;
                } 
                // For testing: also check against plain text (remove in production)
                elseif (in_array($password, $test_passwords) && 
                       ($password === 'superadmin123' && $email === 'superadmin@qc.gov.ph') ||
                       ($password === 'admin123' && $email === 'admin@qc.gov.ph') ||
                       ($password === 'councilor123' && strpos($email, 'councilor') !== false)) {
                    $valid_password = true;
                }
                
                if ($valid_password) {
                    // Login successful
                    $_SESSION['loggedin'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['firstname'] = $user['first_name'];
                    $_SESSION['lastname'] = $user['last_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department'] = $user['department'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['session_id'] = session_id();
                    
                    // Update last login time
                    $update_query = "UPDATE users SET last_login = NOW() WHERE id = :id";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bindParam(':id', $user['id']);
                    $update_stmt->execute();
                    
                    // Log session
                    $session_query = "INSERT INTO sessions (user_id, session_id, ip_address, user_agent) 
                                     VALUES (:user_id, :session_id, :ip_address, :user_agent)";
                    $session_stmt = $conn->prepare($session_query);
                    $session_stmt->bindParam(':user_id', $user['id']);
                    $session_stmt->bindParam(':session_id', session_id());
                    $session_stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
                    $session_stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT']);
                    $session_stmt->execute();
                    
                    // Log login activity
                    $log_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                                 VALUES (:user_id, 'LOGIN', 'User logged in successfully', :ip_address, :user_agent)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bindParam(':user_id', $user['id']);
                    $log_stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
                    $log_stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT']);
                    $log_stmt->execute();
                    
                    // Redirect based on role
                    redirectBasedOnRole($user['role']);
                    exit();
                } else {
                    $error = 'Invalid credentials. Please verify your email and password.';
                    logFailedAttempt($conn, $email);
                }
            } else {
                $error = 'Invalid credentials. Please verify your email and password.';
                logFailedAttempt($conn, $email);
            }
        } else {
            $error = 'System error. Please try again later.';
        }
    }
}

// Function to redirect based on role
function redirectBasedOnRole($role) {
    switch($role) {
        case 'super_admin':
            header("Location: ../superadmin/dashboard.php");
            break;
        case 'admin':
            header("Location: ../admin/dashboard.php");
            break;
        case 'councilor':
            header("Location: ../councilor/dashboard.php");
            break;
        default:
            header("Location: ../index.php");
    }
    exit();
}

// Function to log failed login attempts
function logFailedAttempt($conn, $email) {
    $query = "INSERT INTO audit_logs (action, description, ip_address, user_agent) 
              VALUES ('FAILED_LOGIN', :description, :ip_address, :user_agent)";
    $stmt = $conn->prepare($query);
    $description = "Failed login attempt for email: " . $email;
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
    $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT']);
    $stmt->execute();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Government Authentication System | Quezon City Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* RESET AND BASE STYLES */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --qc-blue: #003366;
            --qc-blue-dark: #002244;
            --qc-blue-light: #004488;
            --qc-gold: #D4AF37;
            --qc-gold-dark: #B8941F;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --red: #C53030;
            --green: #2D8C47;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.12);
            --border-radius: 8px;
            --border-radius-lg: 12px;
        }

        html, body {
            height: 100%;
            width: 100%;
            overflow: hidden;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            background-color: var(--off-white);
            color: var(--gray-dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* MAIN CONTAINER */
        .auth-container {
            display: flex;
            width: 100%;
            max-width: 1200px;
            height: 85vh;
            max-height: 700px;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-light);
        }

        /* LEFT PANEL - GOVERNMENT BRANDING */
        .government-panel {
            flex: 1;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            padding: 40px 30px;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .government-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(45deg, transparent 49%, rgba(212, 175, 55, 0.05) 50%, transparent 51%),
                linear-gradient(-45deg, transparent 49%, rgba(212, 175, 55, 0.05) 50%, transparent 51%);
            background-size: 100px 100px;
        }

        /* GOVERNMENT HEADER */
        .government-header {
            position: relative;
            z-index: 2;
            text-align: center;
            margin-bottom: 30px;
        }

        .seal-container {
            width: 80px;
            height: 80px;
            margin: 0 auto 15px;
            position: relative;
        }

        .seal-outer {
            width: 100%;
            height: 100%;
            border: 3px solid var(--qc-gold);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            position: relative;
        }

        .seal-outer::before {
            content: '';
            position: absolute;
            width: 90%;
            height: 90%;
            border: 2px solid var(--qc-blue);
            border-radius: 50%;
        }

        .seal-icon {
            font-size: 30px;
            color: var(--qc-blue);
        }

        .government-title {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .government-subtitle {
            font-size: 1rem;
            color: var(--qc-gold);
            font-style: italic;
            font-weight: 300;
        }

        /* SYSTEM INFORMATION */
        .system-info {
            position: relative;
            z-index: 2;
            margin-top: auto;
        }

        .system-badge {
            display: inline-block;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            color: var(--qc-gold);
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 25px;
            text-transform: uppercase;
        }

        .system-details {
            color: rgba(255, 255, 255, 0.9);
        }

        .system-name {
            font-size: 1.4rem;
            font-weight: bold;
            margin-bottom: 12px;
            color: var(--white);
        }

        .system-description {
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.8);
        }

        /* RIGHT PANEL - LOGIN FORM */
        .login-panel {
            flex: 1;
            padding: 40px 30px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--white);
        }

        /* LOGIN HEADER */
        .login-header {
            margin-bottom: 30px;
            border-bottom: 2px solid var(--gray-light);
            padding-bottom: 20px;
        }

        .login-title {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 8px;
            letter-spacing: 0.3px;
        }

        .login-subtitle {
            font-size: 0.9rem;
            color: var(--gray);
            line-height: 1.5;
        }

        /* LOGIN FORM CONTAINER */
        .form-container {
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            position: relative;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* ALERT MESSAGES */
        .alert-message {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #FFF5F5;
            color: var(--red);
            border: 1px solid #FED7D7;
            border-left: 4px solid var(--red);
            font-size: 0.9rem;
        }

        .alert-message i {
            font-size: 1.1rem;
        }

        /* FORM ELEMENTS */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }

        .input-container {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1rem;
        }

        .form-input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-family: inherit;
            background: var(--white);
            color: var(--gray-dark);
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
        }

        /* LOGIN BUTTON */
        .login-button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(to right, var(--qc-blue), var(--qc-blue-dark));
            color: var(--white);
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-top: 10px;
            letter-spacing: 0.5px;
        }

        .login-button:hover {
            background: linear-gradient(to right, var(--qc-blue-dark), var(--qc-blue));
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .login-button:active {
            transform: translateY(0);
        }

        /* FORM FOOTER */
        .form-footer {
            text-align: center;
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--qc-blue);
            text-decoration: none;
            font-weight: 600;
            padding: 10px 25px;
            border: 2px solid var(--qc-blue);
            border-radius: var(--border-radius);
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .back-link:hover {
            background: var(--qc-blue);
            color: var(--white);
        }

        /* COPYRIGHT */
        .copyright {
            text-align: center;
            margin-top: 20px;
            color: var(--gray);
            font-size: 0.75rem;
            line-height: 1.4;
        }

        /* RESPONSIVE DESIGN */
        @media (max-width: 992px) {
            .auth-container {
                flex-direction: column;
                max-width: 500px;
                height: auto;
                max-height: 90vh;
            }

            .government-panel, .login-panel {
                padding: 30px 25px;
            }

            .government-panel {
                padding-bottom: 30px;
            }

            .government-title {
                font-size: 1.6rem;
            }

            .login-title {
                font-size: 1.6rem;
            }
        }

        @media (max-width: 576px) {
            body {
                padding: 15px;
            }

            .auth-container {
                max-width: 100%;
                height: auto;
                max-height: 95vh;
            }

            .government-panel, .login-panel {
                padding: 20px 15px;
            }

            .government-title {
                font-size: 1.4rem;
            }

            .login-title {
                font-size: 1.4rem;
            }

            .form-container {
                padding: 20px 15px;
            }

            .government-subtitle {
                font-size: 0.9rem;
            }

            .system-name {
                font-size: 1.2rem;
            }
        }

        /* ANIMATIONS */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <!-- LEFT PANEL - GOVERNMENT BRANDING -->
        <div class="government-panel">
            <div class="government-header fade-in">
                <div class="seal-container">
                    <div class="seal-outer">
                        <i class="fas fa-landmark seal-icon"></i>
                    </div>
                </div>
                <h1 class="government-title">QUEZON CITY GOVERNMENT</h1>
                <div class="government-subtitle">"The Pride of the Filipino Nation"</div>
            </div>

            <div class="system-info fade-in">
                <div class="system-badge">SECURE GOVERNMENT SYSTEM</div>
                <h2 class="system-name">Ordinance & Resolution Tracker</h2>
                <p class="system-description">
                    Official document management system for Quezon City ordinances and resolutions. 
                    This system provides secure access to government records, legislative documents, 
                    and official announcements for authorized personnel only.
                </p>
            </div>
        </div>

        <!-- RIGHT PANEL - LOGIN FORM -->
        <div class="login-panel">
            <div class="login-header fade-in">
                <h2 class="login-title">Quezon City Government</h2>
                <p class="login-subtitle">
                    Access restricted to authorized Quezon City Government personnel. 
                    Please use your official government credentials to proceed.
                </p>
            </div>

            <div class="form-container fade-in">
                <?php if ($error): ?>
                    <div class="alert-message">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="governmentLoginForm">
                    <div class="form-group">
                        <label class="form-label" for="governmentEmail">
                            <i class="fas fa-id-card"></i> Government Email Address
                        </label>
                        <div class="input-container">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" 
                                   id="governmentEmail" 
                                   name="email" 
                                   class="form-input" 
                                   placeholder="user@qc.gov.ph" 
                                   required
                                   pattern="[a-zA-Z0-9._%+-]+@qc\.gov\.ph"
                                   title="Please use your official Quezon City government email"
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                   autocomplete="username">
                        </div>
                        <small style="color: var(--gray); font-size: 0.8rem; display: block; margin-top: 5px;">
                            Must be a valid @qc.gov.ph email address
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="governmentPassword">
                            <i class="fas fa-key"></i> Password
                        </label>
                        <div class="input-container">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   id="governmentPassword" 
                                   name="password" 
                                   class="form-input" 
                                   placeholder="Enter your password" 
                                   required
                                   minlength="6"
                                   autocomplete="current-password">
                            <button type="button" class="password-toggle" id="passwordVisibilityToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="login-button" id="loginSubmitButton">
                        <i class="fas fa-sign-in-alt"></i> Authenticate & Access System
                    </button>
                </form>

                <div class="form-footer">
                    <a href="../index.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Return to Public Portal
                    </a>
                </div>
            </div>

            <div class="copyright">
                <p>© 2026 Quezon City Government. All Rights Reserved.</p>
                <p style="margin-top: 5px; font-size: 0.7rem;">
                    System Version: 3.2.1 | Last Updated: February 7, 2026 | 
                    <i class="fas fa-shield-alt" style="margin-left: 10px;"></i> Security Level: High
                </p>
            </div>
        </div>
    </div>

    <script>
        // DOM Elements
        const loginForm = document.getElementById('governmentLoginForm');
        const emailInput = document.getElementById('governmentEmail');
        const passwordInput = document.getElementById('governmentPassword');
        const passwordToggle = document.getElementById('passwordVisibilityToggle');
        const loginButton = document.getElementById('loginSubmitButton');

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus on email input with delay for better UX
            setTimeout(() => {
                emailInput.focus();
                emailInput.select();
            }, 300);

            // Add animation to form elements
            const formElements = document.querySelectorAll('.form-group, .form-footer');
            formElements.forEach((el, index) => {
                el.style.animationDelay = `${index * 100}ms`;
                el.classList.add('fade-in');
            });
        });

        // Toggle Password Visibility
        passwordToggle.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? 
                '<i class="fas fa-eye"></i>' : 
                '<i class="fas fa-eye-slash"></i>';
            
            // Update title for accessibility
            this.title = type === 'password' ? 'Show password' : 'Hide password';
        });

        // Form Validation
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Clear previous error states
            clearErrors();
            
            // Validate form
            if (validateForm()) {
                // Show loading state
                showLoadingState();
                
                // Submit form after delay for UX
                setTimeout(() => {
                    this.submit();
                }, 800);
            }
        });

        // Real-time Validation
        emailInput.addEventListener('blur', validateEmail);
        passwordInput.addEventListener('input', validatePasswordStrength);

        // Validation Functions
        function validateForm() {
            let isValid = true;
            
            // Validate email
            if (!validateEmail()) {
                isValid = false;
            }
            
            // Validate password
            if (!validatePassword()) {
                isValid = false;
            }
            
            return isValid;
        }

        function validateEmail() {
            const email = emailInput.value.trim();
            const emailPattern = /^[a-zA-Z0-9._%+-]+@qc\.gov\.ph$/;
            
            if (!email) {
                showError(emailInput, 'Government email address is required');
                return false;
            }
            
            if (!emailPattern.test(email)) {
                showError(emailInput, 'Please use a valid @qc.gov.ph email address');
                return false;
            }
            
            return true;
        }

        function validatePassword() {
            const password = passwordInput.value;
            
            if (!password) {
                showError(passwordInput, 'Password is required');
                return false;
            }
            
            if (password.length < 6) {
                showError(passwordInput, 'Password must be at least 6 characters');
                return false;
            }
            
            return true;
        }

        function validatePasswordStrength() {
            const password = passwordInput.value;
            const strengthIndicator = document.getElementById('passwordStrength') || createPasswordStrengthIndicator();
            
            if (password.length === 0) {
                strengthIndicator.textContent = '';
                return;
            }
            
            let strength = 'Weak';
            let color = 'var(--red)';
            
            if (password.length >= 8 && /[A-Z]/.test(password) && /[0-9]/.test(password)) {
                strength = 'Strong';
                color = 'var(--green)';
            } else if (password.length >= 6) {
                strength = 'Medium';
                color = 'var(--qc-gold)';
            }
            
            strengthIndicator.textContent = `Password Strength: ${strength}`;
            strengthIndicator.style.color = color;
        }

        function createPasswordStrengthIndicator() {
            const indicator = document.createElement('small');
            indicator.id = 'passwordStrength';
            indicator.style.cssText = 'display: block; margin-top: 5px; font-size: 0.8rem;';
            passwordInput.parentElement.appendChild(indicator);
            return indicator;
        }

        // Error Handling
        function showError(inputElement, message) {
            // Remove existing error
            const existingError = inputElement.parentElement.querySelector('.error-message');
            if (existingError) existingError.remove();
            
            // Create error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.style.cssText = `
                color: var(--red);
                font-size: 0.8rem;
                margin-top: 5px;
                display: flex;
                align-items: center;
                gap: 5px;
            `;
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            
            // Add to DOM
            inputElement.parentElement.appendChild(errorDiv);
            
            // Highlight input
            inputElement.style.borderColor = 'var(--red)';
            
            // Focus on input
            inputElement.focus();
        }

        function clearErrors() {
            // Remove error messages
            document.querySelectorAll('.error-message').forEach(el => el.remove());
            
            // Reset input borders
            document.querySelectorAll('.form-input').forEach(input => {
                input.style.borderColor = '';
            });
        }

        // Loading State
        function showLoadingState() {
            // Disable button
            loginButton.disabled = true;
            loginButton.style.opacity = '0.7';
            loginButton.style.cursor = 'not-allowed';
            
            // Update button text
            const originalText = loginButton.innerHTML;
            loginButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
            
            // Store original text for reset
            loginButton.dataset.originalText = originalText;
        }

        // Handle form reset on page reload
        window.addEventListener('pageshow', function(event) {
            // Reset button if it's in loading state
            if (loginButton.disabled && loginButton.dataset.originalText) {
                loginButton.disabled = false;
                loginButton.style.opacity = '';
                loginButton.style.cursor = '';
                loginButton.innerHTML = loginButton.dataset.originalText;
            }
        });

        // Enter key support
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.target.matches('.password-toggle')) {
                // If focus is on either input, trigger form submission
                if (document.activeElement === emailInput || document.activeElement === passwordInput) {
                    e.preventDefault();
                    loginForm.dispatchEvent(new Event('submit'));
                }
            }
        });

        // Accessibility enhancements
        emailInput.addEventListener('focus', function() {
            this.parentElement.querySelector('.input-icon').style.color = 'var(--qc-blue)';
        });

        emailInput.addEventListener('blur', function() {
            this.parentElement.querySelector('.input-icon').style.color = '';
        });

        passwordInput.addEventListener('focus', function() {
            this.parentElement.querySelector('.input-icon').style.color = 'var(--qc-blue)';
        });

        passwordInput.addEventListener('blur', function() {
            this.parentElement.querySelector('.input-icon').style.color = '';
        });

        // Prevent scrolling
        document.addEventListener('wheel', function(e) {
            if (e.target.closest('.auth-container')) {
                e.preventDefault();
            }
        }, { passive: false });

        // Handle touch events to prevent scrolling on mobile
        let touchStartY = 0;
        
        document.addEventListener('touchstart', function(e) {
            if (e.target.closest('.auth-container')) {
                touchStartY = e.touches[0].clientY;
            }
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (e.target.closest('.auth-container')) {
                e.preventDefault();
            }
        }, { passive: false });
    </script>
</body>
</html>