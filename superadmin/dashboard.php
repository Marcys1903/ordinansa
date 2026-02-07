<?php
// dashboard.php - SUPER ADMIN ONLY DASHBOARD
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user is SUPER ADMIN
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    // Redirect based on role
    switch($_SESSION['role']) {
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

// Get dashboard statistics (example data - replace with actual queries)
$stats = [
    'total_users' => 0,
    'total_admins' => 0,
    'total_councilors' => 0,
    'total_documents' => 0,
    'pending_documents' => 0,
    'active_sessions' => 0
];

// Fetch actual statistics
try {
    // Total users
    $query = "SELECT COUNT(*) as count FROM users";
    $stmt = $conn->query($query);
    $stats['total_users'] = $stmt->fetch()['count'];
    
    // Total admins
    $query = "SELECT COUNT(*) as count FROM users WHERE role = 'admin'";
    $stmt = $conn->query($query);
    $stats['total_admins'] = $stmt->fetch()['count'];
    
    // Total councilors
    $query = "SELECT COUNT(*) as count FROM users WHERE role = 'councilor'";
    $stmt = $conn->query($query);
    $stats['total_councilors'] = $stmt->fetch()['count'];
    
    // Total documents
    $query = "SELECT COUNT(*) as count FROM documents";
    $stmt = $conn->query($query);
    $stats['total_documents'] = $stmt->fetch()['count'];
    
    // Pending documents
    $query = "SELECT COUNT(*) as count FROM documents WHERE status = 'pending'";
    $stmt = $conn->query($query);
    $stats['pending_documents'] = $stmt->fetch()['count'];
    
    // Active sessions (simplified - in production use session table)
    $stats['active_sessions'] = rand(5, 50);
    
} catch (PDOException $e) {
    // Handle error silently or log it
    error_log("Dashboard statistics error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard | QC Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --qc-blue: #003366;
            --qc-blue-dark: #002244;
            --qc-blue-light: #004488;
            --qc-gold: #D4AF37;
            --qc-gold-dark: #B8941F;
            --qc-green: #2D8C47;
            --qc-green-dark: #1F6C37;
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
            --shadow-xl: 0 12px 24px rgba(0,0,0,0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --sidebar-width: 280px;
            --header-height: 180px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            background: var(--off-white);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* MAIN CONTENT WRAPPER */
        .main-wrapper {
            display: flex;
            min-height: calc(100vh - var(--header-height));
            margin-top: var(--header-height);
        }
        
        /* SIDEBAR STYLES */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--qc-blue-dark) 0%, var(--qc-blue) 100%);
            color: var(--white);
            position: fixed;
            top: var(--header-height);
            left: 0;
            height: calc(100vh - var(--header-height));
            z-index: 100;
            overflow-y: auto;
            box-shadow: 2px 0 8px rgba(0,0,0,0.1);
            border-right: 3px solid var(--qc-gold);
            transition: transform 0.3s ease;
        }
        
        .sidebar-content {
            padding: 30px 20px;
            height: 100%;
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 5px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            transition: all 0.3s ease;
            font-size: 0.95rem;
            letter-spacing: 0.3px;
            border-left: 3px solid transparent;
        }
        
        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            border-left: 3px solid var(--qc-gold);
            transform: translateX(5px);
        }
        
        .sidebar-menu a.active {
            background: rgba(212, 175, 55, 0.2);
            color: var(--qc-gold);
            border-left: 3px solid var(--qc-gold);
            font-weight: bold;
        }
        
        .sidebar-menu i {
            width: 25px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .sidebar-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-subtitle {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
        }
        
        /* MAIN CONTENT AREA */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            overflow-y: auto;
            background: var(--off-white);
        }
        
        /* GOVERNMENT HEADER STYLE */
        .government-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 15px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: var(--shadow-md);
            border-bottom: 3px solid var(--qc-gold);
            height: var(--header-height);
        }
        
        .header-top-bar {
            background: rgba(0, 0, 0, 0.2);
            padding: 8px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
        }
        
        .header-top-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .government-seal {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .seal-icon-mini {
            font-size: 20px;
            color: var(--qc-gold);
        }
        
        .current-date {
            font-weight: 600;
            color: var(--qc-gold);
            background: rgba(0, 0, 0, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .main-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 15px 20px;
        }
        
        .qc-logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .qc-seal-large {
            width: 60px;
            height: 60px;
            border: 3px solid var(--qc-gold);
            border-radius: 50%;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .qc-seal-large::before {
            content: '';
            position: absolute;
            width: 85%;
            height: 85%;
            border: 2px solid var(--qc-blue);
            border-radius: 50%;
        }
        
        .seal-icon-large {
            font-size: 28px;
            color: var(--qc-blue);
            z-index: 2;
        }
        
        .site-title-container h1 {
            font-size: 1.6rem;
            font-weight: bold;
            color: var(--white);
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .site-title-container p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }
        
        .user-auth-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-welcome-panel {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px 20px;
            border-radius: var(--border-radius);
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--qc-gold);
            color: var(--qc-blue-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .user-info {
            color: var(--white);
        }
        
        .user-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 3px;
        }
        
        .user-role {
            font-size: 0.8rem;
            color: var(--qc-gold);
            background: rgba(0, 0, 0, 0.2);
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-block;
            text-transform: uppercase;
            font-weight: bold;
        }
        
        .logout-button {
            padding: 8px 20px;
            background: transparent;
            color: var(--white);
            border: 2px solid var(--qc-gold);
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-button:hover {
            background: var(--qc-gold);
            color: var(--qc-blue-dark);
        }
        
        /* DASHBOARD CONTENT */
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 0;
        }
        
        .dashboard-header {
            margin-bottom: 40px;
            padding: 25px;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(212, 175, 55, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                linear-gradient(45deg, transparent 49%, rgba(212, 175, 55, 0.1) 50%, transparent 51%),
                linear-gradient(-45deg, transparent 49%, rgba(212, 175, 55, 0.1) 50%, transparent 51%);
            background-size: 80px 80px;
        }
        
        .dashboard-title {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .dashboard-title h1 {
            font-size: 2.2rem;
            font-weight: bold;
            color: var(--white);
        }
        
        .dashboard-title i {
            color: var(--qc-gold);
            font-size: 2rem;
        }
        
        .dashboard-subtitle {
            position: relative;
            z-index: 2;
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
        }
        
        .system-badge {
            display: inline-block;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            color: var(--qc-gold);
            padding: 8px 25px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 25px;
            text-transform: uppercase;
            position: relative;
            z-index: 2;
        }
        
        /* STATISTICS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--qc-blue);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--qc-blue), var(--qc-gold));
        }
        
        .stat-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 1.8rem;
        }
        
        .stat-value {
            font-size: 2.8rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 10px;
            line-height: 1;
        }
        
        .stat-label {
            color: var(--gray-dark);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 15px;
            letter-spacing: 0.3px;
        }
        
        .stat-change {
            font-size: 0.9rem;
            padding: 5px 15px;
            border-radius: 20px;
            display: inline-block;
            font-weight: 600;
        }
        
        .stat-change.positive {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
        }
        
        .stat-change.negative {
            background: rgba(197, 48, 48, 0.1);
            color: var(--red);
        }
        
        /* QUICK ACTIONS */
        .quick-actions-section {
            margin-bottom: 40px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--gray-light);
        }
        
        .section-title {
            font-size: 1.6rem;
            font-weight: bold;
            color: var(--qc-blue);
            display: flex;
            align-items: center;
            gap: 15px;
            letter-spacing: 0.3px;
        }
        
        .section-title i {
            color: var(--qc-gold);
            font-size: 1.5rem;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
        }
        
        .action-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            text-align: center;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--gray-dark);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--qc-blue);
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
        }
        
        .action-card:hover .action-icon {
            background: var(--white);
            color: var(--qc-blue);
        }
        
        .action-card:hover .action-title {
            color: var(--white);
        }
        
        .action-card:hover .action-description {
            color: rgba(255, 255, 255, 0.9);
        }
        
        .action-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 1.8rem;
            transition: all 0.3s ease;
        }
        
        .action-title {
            color: var(--qc-blue);
            margin-bottom: 15px;
            font-size: 1.3rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .action-description {
            color: var(--gray-dark);
            font-size: 0.95rem;
            line-height: 1.6;
            transition: all 0.3s ease;
        }
        
        /* RECENT ACTIVITY */
        .recent-activity {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .activity-list {
            list-style: none;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            background: var(--off-white);
            transform: translateX(5px);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 50px;
            height: 50px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-blue);
            font-size: 1.2rem;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .activity-time {
            color: var(--gray);
            font-size: 0.85rem;
        }
        
        /* FOOTER */
        .government-footer {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 40px 0 20px;
            margin-top: 60px;
            position: relative;
            overflow: hidden;
            margin-left: var(--sidebar-width);
        }
        
        .government-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                linear-gradient(45deg, transparent 49%, rgba(212, 175, 55, 0.05) 50%, transparent 51%),
                linear-gradient(-45deg, transparent 49%, rgba(212, 175, 55, 0.05) 50%, transparent 51%);
            background-size: 100px 100px;
        }
        
        .footer-content {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px 30px;
        }
        
        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--white);
            font-weight: 600;
            letter-spacing: 0.5px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--qc-gold);
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 12px;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }
        
        .footer-links a:hover {
            color: var(--qc-gold);
            transform: translateX(5px);
        }
        
        .footer-links i {
            width: 20px;
            color: var(--qc-gold);
            font-size: 0.9rem;
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            position: relative;
            z-index: 2;
            max-width: 1400px;
            margin: 0 auto;
            padding-left: 20px;
            padding-right: 20px;
        }
        
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            padding: 8px 20px;
            border-radius: 20px;
            margin-top: 15px;
            color: var(--qc-gold);
            font-size: 0.8rem;
        }
        
        /* RESPONSIVE DESIGN */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .main-header-content {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .qc-logo-container {
                justify-content: center;
            }
            
            .user-auth-section {
                flex-direction: column;
                gap: 15px;
            }
            
            .user-welcome-panel {
                flex-direction: column;
                text-align: center;
            }
            
            .dashboard-title h1 {
                font-size: 1.8rem;
            }
            
            /* Sidebar becomes overlay on mobile */
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .government-footer {
                margin-left: 0;
            }
            
            /* Mobile menu toggle button */
            .mobile-menu-toggle {
                display: block;
                position: fixed;
                top: var(--header-height);
                right: 20px;
                background: var(--qc-gold);
                color: var(--qc-blue-dark);
                border: none;
                padding: 10px 15px;
                border-radius: var(--border-radius);
                cursor: pointer;
                z-index: 1001;
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .section-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .dashboard-header {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 2.2rem;
            }
        }
        
        @media (max-width: 576px) {
            .header-top-content {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .main-content {
                padding: 20px 15px;
            }
            
            .stat-card,
            .action-card,
            .recent-activity {
                padding: 20px;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
            }
            
            :root {
                --header-height: 200px;
            }
        }
        
        /* ANIMATIONS */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        .slide-in {
            animation: slideIn 0.4s ease-out;
        }
        
        /* SUPER ADMIN BADGE */
        .super-admin-badge {
            display: inline-block;
            background: linear-gradient(135deg, var(--qc-gold) 0%, var(--qc-gold-dark) 100%);
            color: var(--qc-blue-dark);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: 10px;
            border: 1px solid rgba(212, 175, 55, 0.5);
        }
        
        /* Mobile menu toggle button (hidden by default on desktop) */
        .mobile-menu-toggle {
            display: none;
        }
        
        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
    </style>
</head>
<body>
    <!-- Government Header -->
    <header class="government-header">
        <div class="header-top-bar">
            <div class="header-top-content">
                <div class="government-seal">
                    <i class="fas fa-city seal-icon-mini"></i>
                    <span>City Government of Quezon</span>
                </div>
                <div class="current-date" id="currentDate">Loading date...</div>
            </div>
        </div>
        
        <div class="main-header-content">
            <div class="qc-logo-container">
                <div class="qc-seal-large">
                    <i class="fas fa-landmark seal-icon-large"></i>
                </div>
                <div class="site-title-container">
                    <h1>QC Ordinance Tracker System</h1>
                    <p>Super Administrator Dashboard | Full System Control</p>
                </div>
            </div>
            
            <div class="user-auth-section">
                <div class="user-welcome-panel">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                        <div class="user-role">
                            SUPER ADMINISTRATOR
                            <span class="super-admin-badge">Full Access</span>
                        </div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-button">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <div class="sidebar-header">
                    <h3 class="sidebar-title">
                        <i class="fas fa-cog"></i> Control Panel
                    </h3>
                    <p class="sidebar-subtitle">Super Admin Tools</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
                <!-- If sidebar.php doesn't exist or you want a default, use this: -->
                <!--
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="users/manage.php"><i class="fas fa-users-cog"></i> User Management</a></li>
                    <li><a href="documents/approve.php"><i class="fas fa-file-signature"></i> Approve Documents</a></li>
                    <li><a href="system/settings.php"><i class="fas fa-cogs"></i> System Settings</a></li>
                    <li><a href="reports/generate.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="audit/logs.php"><i class="fas fa-clipboard-list"></i> Audit Logs</a></li>
                    <li><a href="backup/management.php"><i class="fas fa-database"></i> Backup System</a></li>
                    <li><a href="../index.php"><i class="fas fa-globe"></i> Public Portal</a></li>
                </ul>
                -->
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-container">
                <!-- Dashboard Header -->
                <div class="dashboard-header fade-in">
                    <div class="system-badge">SUPER ADMINISTRATOR DASHBOARD</div>
                    <div class="dashboard-title">
                        <i class="fas fa-shield-alt"></i>
                        <h1>System Administration Center</h1>
                    </div>
                    <p class="dashboard-subtitle">
                        Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>. You have full system control 
                        and can manage all aspects of the QC Ordinance Tracker System from this dashboard.
                    </p>
                </div>

                <!-- Statistics Grid -->
                <div class="stats-grid fade-in">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                        <div class="stat-label">Total System Users</div>
                        <div class="stat-change positive">+12% this month</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_admins']); ?></div>
                        <div class="stat-label">Administrator Accounts</div>
                        <div class="stat-change positive">+3 this month</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_councilors']); ?></div>
                        <div class="stat-label">Councilor Accounts</div>
                        <div class="stat-change positive">+5 this month</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_documents']); ?></div>
                        <div class="stat-label">Total Documents</div>
                        <div class="stat-change positive">+24 this week</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['pending_documents']); ?></div>
                        <div class="stat-label">Pending Approvals</div>
                        <div class="stat-change negative">Needs attention</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-signal"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['active_sessions']); ?></div>
                        <div class="stat-label">Active Sessions</div>
                        <div class="stat-change positive">System active</div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions-section fade-in">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-bolt"></i> Quick System Actions
                        </h2>
                    </div>
                    
                    <div class="actions-grid">
                        <a href="users/manage.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-user-cog"></i>
                            </div>
                            <h3 class="action-title">Manage Users</h3>
                            <p class="action-description">
                                Add, edit, or remove system users. Manage roles and permissions.
                            </p>
                        </a>
                        
                        <a href="documents/approve.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <h3 class="action-title">Approve Documents</h3>
                            <p class="action-description">
                                Review and approve pending ordinances and resolutions.
                            </p>
                        </a>
                        
                        <a href="system/settings.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <h3 class="action-title">System Settings</h3>
                            <p class="action-description">
                                Configure system preferences, security, and global settings.
                            </p>
                        </a>
                        
                        <a href="reports/generate.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <h3 class="action-title">System Reports</h3>
                            <p class="action-description">
                                Generate detailed reports on system usage and activity.
                            </p>
                        </a>
                        
                        <a href="audit/logs.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h3 class="action-title">Audit Logs</h3>
                            <p class="action-description">
                                View system activity logs and security audit trails.
                            </p>
                        </a>
                        
                        <a href="backup/management.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-database"></i>
                            </div>
                            <h3 class="action-title">Backup System</h3>
                            <p class="action-description">
                                Create and manage system backups and recovery points.
                            </p>
                        </a>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="recent-activity fade-in">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-history"></i> Recent System Activity
                        </h2>
                    </div>
                    
                    <ul class="activity-list">
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">New Administrator Added</div>
                                <div class="activity-time">Just now</div>
                            </div>
                        </li>
                        
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-file-upload"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Document Uploaded by Councilor Dela Cruz</div>
                                <div class="activity-time">5 minutes ago</div>
                            </div>
                        </li>
                        
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Security Settings Updated</div>
                                <div class="activity-time">1 hour ago</div>
                            </div>
                        </li>
                        
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-database"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Automatic Backup Completed</div>
                                <div class="activity-time">2 hours ago</div>
                            </div>
                        </li>
                        
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-user-lock"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Password Reset for User #42</div>
                                <div class="activity-time">Yesterday, 4:30 PM</div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>QC Ordinance System</h3>
                    <p>
                        Super Administrator Dashboard for the Quezon City Ordinance Tracker System. 
                        This interface provides full system control and management capabilities.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>System Links</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="users/manage.php"><i class="fas fa-users-cog"></i> User Management</a></li>
                    <li><a href="system/settings.php"><i class="fas fa-sliders-h"></i> System Settings</a></li>
                    <li><a href="audit/logs.php"><i class="fas fa-scroll"></i> Audit Logs</a></li>
                    <li><a href="../index.php"><i class="fas fa-globe"></i> Public Portal</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support & Security</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-life-ring"></i> System Support</a></li>
                    <li><a href="#"><i class="fas fa-file-contract"></i> Documentation</a></li>
                    <li><a href="#"><i class="fas fa-shield-alt"></i> Security Center</a></li>
                    <li><a href="#"><i class="fas fa-envelope"></i> Contact Developers</a></li>
                    <li><a href="#"><i class="fas fa-exclamation-triangle"></i> Report Issues</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Super Administrator Dashboard - Restricted Access.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Security Level: Maximum | Access: Super Admin Only | Session: Active
            </div>
        </div>
    </footer>

    <script>
        // Set current date
        const currentDate = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        };
        document.getElementById('currentDate').textContent = currentDate.toLocaleDateString('en-US', options);
        
        // Mobile menu toggle functionality
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        if (mobileMenuToggle && sidebar) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
            });
            
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            });
            
            // Close sidebar when clicking on a link (for mobile)
            sidebar.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' && window.innerWidth <= 992) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                }
            });
        }
        
        // Add animation to elements on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                }
            });
        }, observerOptions);
        
        // Observe all stat cards and action cards
        document.querySelectorAll('.stat-card, .action-card').forEach(card => {
            observer.observe(card);
        });
        
        // Auto-refresh statistics every 60 seconds
        setInterval(() => {
            // In a real application, you would fetch updated statistics via AJAX
            console.log('Auto-refreshing dashboard statistics...');
            // Example: fetch('/api/dashboard/stats').then(...)
        }, 60000);
        
        // Add confirmation for sensitive actions
        document.querySelectorAll('.action-card[href*="delete"], .action-card[href*="remove"]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to perform this action? This may affect system operations.')) {
                    e.preventDefault();
                }
            });
        });
        
        // Session timeout warning (30 minutes)
        let idleTimer;
        function resetIdleTimer() {
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                if (confirm('Your session will expire soon due to inactivity. Click OK to stay logged in.')) {
                    resetIdleTimer();
                } else {
                    window.location.href = '../logout.php';
                }
            }, 30 * 60 * 1000); // 30 minutes
        }
        
        // Reset idle timer on user activity
        ['mousemove', 'keypress', 'click', 'scroll'].forEach(event => {
            document.addEventListener(event, resetIdleTimer, { passive: true });
        });
        
        // Initialize idle timer
        resetIdleTimer();
        
        // Initialize animations on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add fade-in class to initially visible elements
            const visibleElements = document.querySelectorAll('.government-header, .dashboard-header');
            visibleElements.forEach(el => {
                el.classList.add('fade-in');
            });
            
            // Check if we're on mobile and show/hide mobile menu button
            function checkMobileMenu() {
                if (window.innerWidth <= 992) {
                    mobileMenuToggle.style.display = 'block';
                } else {
                    mobileMenuToggle.style.display = 'none';
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                }
            }
            
            checkMobileMenu();
            window.addEventListener('resize', checkMobileMenu);
        });
    </script>
</body>
</html>