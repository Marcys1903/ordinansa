<?php
// dashboard.php
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Include database configuration
require_once '../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Get user info
$user_id = $_SESSION['user_id'];
$query = "SELECT * FROM users WHERE id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch();

// Redirect based on role if accessing wrong dashboard
$current_page = basename($_SERVER['PHP_SELF']);
$expected_page = $_SESSION['role'] . '.php';

if ($current_page !== $expected_page) {
    redirectBasedOnRole($_SESSION['role']);
    exit();
}

function redirectBasedOnRole($role) {
    switch($role) {
        case 'super_admin':
            header("Location: super_admin.php");
            break;
        case 'admin':
            header("Location: admin.php");
            break;
        case 'councilor':
            header("Location: councilor.php");
            break;
        default:
            header("Location: ../login.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?> Dashboard | Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --qc-blue: #003366;
            --qc-blue-light: #004488;
            --qc-blue-dark: #002244;
            --qc-gold: #D4AF37;
            --qc-gold-light: #E6C158;
            --qc-gold-dark: #B8941F;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --green: #2D8C47;
            --red: #C53030;
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.12);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            background: var(--off-white);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: var(--qc-blue);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: var(--qc-blue-dark);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-blue);
            font-size: 1.2rem;
        }
        
        .logo-text h2 {
            font-size: 1.2rem;
            margin-bottom: 3px;
        }
        
        .logo-text p {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: var(--qc-gold);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .user-details h3 {
            font-size: 1rem;
            margin-bottom: 3px;
        }
        
        .user-details p {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        /* Navigation Styles */
        .nav-section {
            margin: 25px 0;
        }
        
        .section-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--qc-gold);
            padding: 0 20px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .nav-menu {
            list-style: none;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.05);
            color: white;
            border-left-color: var(--qc-gold);
        }
        
        .nav-link.active {
            background: rgba(212, 175, 55, 0.1);
            color: white;
            border-left-color: var(--qc-gold);
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .submenu {
            list-style: none;
            margin-left: 20px;
            padding-left: 25px;
            border-left: 1px solid rgba(255,255,255,0.1);
            margin-top: 5px;
        }
        
        .submenu-item {
            margin-bottom: 3px;
        }
        
        .submenu-link {
            display: block;
            padding: 10px 15px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s;
            border-radius: 4px;
        }
        
        .submenu-link:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }
        
        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            transition: all 0.3s;
        }
        
        .topbar {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        
        .page-title h1 {
            font-size: 1.8rem;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .page-title p {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-input {
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            width: 300px;
            font-size: 0.9rem;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: var(--border-radius);
            transition: all 0.3s;
        }
        
        .user-toggle:hover {
            background: var(--off-white);
        }
        
        .user-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            min-width: 200px;
            display: none;
            z-index: 1000;
        }
        
        .user-menu:hover .user-menu-dropdown {
            display: block;
        }
        
        .user-menu-item {
            display: block;
            padding: 12px 20px;
            color: var(--gray-dark);
            text-decoration: none;
            transition: all 0.3s;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .user-menu-item:last-child {
            border-bottom: none;
        }
        
        .user-menu-item:hover {
            background: var(--off-white);
            color: var(--qc-blue);
        }
        
        /* Content Area */
        .content-area {
            padding: 30px;
            min-height: calc(100vh - 80px);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border-left: 5px solid var(--qc-blue);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            background: rgba(0, 51, 102, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            color: var(--qc-blue);
            font-size: 1.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        /* Dashboard Sections */
        .dashboard-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .section-header h2 {
            color: var(--qc-blue);
            font-size: 1.4rem;
        }
        
        .view-all {
            color: var(--qc-blue);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .view-all:hover {
            text-decoration: underline;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: 250px;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .topbar {
                padding: 15px 20px;
            }
            
            .search-input {
                width: 200px;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .search-input {
                width: 150px;
            }
        }
        
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--qc-blue);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        @media (max-width: 992px) {
            .mobile-toggle {
                display: block;
            }
        }
        
        /* Role-specific badge */
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            background: var(--qc-gold);
            color: var(--qc-blue);
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            flex: 1;
            min-width: 200px;
            background: white;
            border: 2px solid var(--qc-blue);
            color: var(--qc-blue);
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .action-btn:hover {
            background: var(--qc-blue);
            color: white;
            transform: translateY(-3px);
        }
        
        .action-btn i {
            font-size: 2rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div class="mobile-toggle" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </div>
            
            <div class="page-title">
                <h1><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?> Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
            </div>
            
            <div class="topbar-actions">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Search documents...">
                </div>
                
                <div class="user-menu">
                    <button class="user-toggle">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                        </div>
                        <span><?php echo htmlspecialchars($user['first_name']); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    
                    <div class="user-menu-dropdown">
                        <a href="profile.php" class="user-menu-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="settings.php" class="user-menu-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="user-menu-item">
                            <i class="fas fa-user-tag"></i> Role: 
                            <span class="role-badge"><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></span>
                        </div>
                        <a href="../logout.php" class="user-menu-item" style="color: var(--red);">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Content Area -->
        <div class="content-area">
            <?php
            // Content will be loaded here based on the specific dashboard
            ?>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');
        
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 992) {
                if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
        
        // Set active navigation link
        document.addEventListener('DOMContentLoaded', () => {
            const currentPage = window.location.pathname.split('/').pop();
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>