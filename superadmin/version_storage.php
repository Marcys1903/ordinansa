<?php
// approval_control.php - Amendment Approval Control Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission for amendment approvals
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin', 'councilor'])) {
    header("Location: ../unauthorized.php");
    exit();
}

// Include database configuration
require_once '../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$query = "SELECT * FROM users WHERE id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch();

// Handle approval/rejection actions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'];
        $amendment_id = $_POST['amendment_id'];
        $comments = $_POST['comments'] ?? '';
        
        // Get amendment details
        $amendment_query = "SELECT * FROM amendment_submissions WHERE id = :id";
        $stmt = $conn->prepare($amendment_query);
        $stmt->bindParam(':id', $amendment_id);
        $stmt->execute();
        $amendment = $stmt->fetch();
        
        if (!$amendment) {
            throw new Exception("Amendment not found");
        }
        
        $conn->beginTransaction();
        
        if ($action === 'approve') {
            // Update amendment status
            $update_query = "UPDATE amendment_submissions SET status = 'approved', approved_by = :approved_by, approved_at = NOW() WHERE id = :id";
            $stmt = $conn->prepare($update_query);
            $stmt->bindParam(':approved_by', $user_id);
            $stmt->bindParam(':id', $amendment_id);
            $stmt->execute();
            
            // Create approval record
            $approval_query = "INSERT INTO amendment_approval_signatures (amendment_id, signatory_id, signature_type, signature_status, signed_at, signature_notes) 
                              VALUES (:amendment_id, :signatory_id, :sig_type, 'signed', NOW(), :notes)";
            $stmt = $conn->prepare($approval_query);
            $stmt->bindParam(':amendment_id', $amendment_id);
            $stmt->bindParam(':signatory_id', $user_id);
            $stmt->bindParam(':sig_type', $user_role);
            $stmt->bindParam(':notes', $comments);
            $stmt->execute();
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                           VALUES (:user_id, 'AMENDMENT_APPROVE', 'Approved amendment: {$amendment['amendment_number']}', :ip, :agent)";
            
            $success_message = "Amendment approved successfully!";
            
        } elseif ($action === 'reject') {
            // Update amendment status
            $update_query = "UPDATE amendment_submissions SET status = 'rejected', rejection_reason = :reason WHERE id = :id";
            $stmt = $conn->prepare($update_query);
            $stmt->bindParam(':reason', $comments);
            $stmt->bindParam(':id', $amendment_id);
            $stmt->execute();
            
            // Create rejection record
            $rejection_query = "INSERT INTO amendment_approval_signatures (amendment_id, signatory_id, signature_type, signature_status, signature_notes) 
                               VALUES (:amendment_id, :signatory_id, :sig_type, 'rejected', :notes)";
            $stmt = $conn->prepare($rejection_query);
            $stmt->bindParam(':amendment_id', $amendment_id);
            $stmt->bindParam(':signatory_id', $user_id);
            $stmt->bindParam(':sig_type', $user_role);
            $stmt->bindParam(':notes', $comments);
            $stmt->execute();
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                           VALUES (:user_id, 'AMENDMENT_REJECT', 'Rejected amendment: {$amendment['amendment_number']}', :ip, :agent)";
            
            $success_message = "Amendment rejected successfully!";
            
        } elseif ($action === 'return') {
            // Return for revision
            $update_query = "UPDATE amendment_submissions SET status = 'draft' WHERE id = :id";
            $stmt = $conn->prepare($update_query);
            $stmt->bindParam(':id', $amendment_id);
            $stmt->execute();
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                           VALUES (:user_id, 'AMENDMENT_RETURN', 'Returned amendment for revision: {$amendment['amendment_number']}', :ip, :agent)";
            
            $success_message = "Amendment returned for revision!";
        }
        
        // Add workflow step
        $workflow_query = "INSERT INTO amendment_approval_workflow (amendment_id, workflow_step, assigned_to, status, comments, action_by) 
                          VALUES (:amendment_id, :step, :assigned_to, :status, :comments, :action_by)";
        $stmt = $conn->prepare($workflow_query);
        $stmt->bindParam(':amendment_id', $amendment_id);
        $stmt->bindParam(':step', $action);
        $stmt->bindParam(':assigned_to', $user_id);
        $stmt->bindParam(':status', $action);
        $stmt->bindParam(':comments', $comments);
        $stmt->bindParam(':action_by', $user_id);
        $stmt->execute();
        
        // Log audit action
        if (isset($audit_query)) {
            $stmt = $conn->prepare($audit_query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
            $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
            $stmt->execute();
        }
        
        $conn->commit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error processing amendment: " . $e->getMessage();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get amendments for approval
$status_filter = $_GET['status'] ?? 'pending';
$search_term = $_GET['search'] ?? '';

$amendments_query = "SELECT a.*, 
                    o.ordinance_number, o.title as doc_title,
                    r.resolution_number, r.title as res_title,
                    u1.first_name as submitter_first, u1.last_name as submitter_last,
                    u2.first_name as reviewer_first, u2.last_name as reviewer_last,
                    COUNT(DISTINCT av.id) as vote_count,
                    SUM(CASE WHEN av.vote_type = 'yes' THEN 1 ELSE 0 END) as yes_votes,
                    SUM(CASE WHEN av.vote_type = 'no' THEN 1 ELSE 0 END) as no_votes
                    FROM amendment_submissions a
                    LEFT JOIN ordinances o ON a.document_id = o.id AND a.document_type = 'ordinance'
                    LEFT JOIN resolutions r ON a.document_id = r.id AND a.document_type = 'resolution'
                    LEFT JOIN users u1 ON a.submitted_by = u1.id
                    LEFT JOIN users u2 ON a.reviewed_by = u2.id
                    LEFT JOIN amendment_voting av ON a.id = av.amendment_id
                    WHERE 1=1";

// Add status filter
if ($status_filter === 'pending') {
    $amendments_query .= " AND a.status IN ('pending', 'under_review')";
} elseif ($status_filter === 'approved') {
    $amendments_query .= " AND a.status = 'approved'";
} elseif ($status_filter === 'rejected') {
    $amendments_query .= " AND a.status = 'rejected'";
} elseif ($status_filter === 'draft') {
    $amendments_query .= " AND a.status = 'draft'";
}

// Add search filter
if (!empty($search_term)) {
    $amendments_query .= " AND (a.title LIKE :search OR a.amendment_number LIKE :search OR 
                              o.ordinance_number LIKE :search OR r.resolution_number LIKE :search)";
}

$amendments_query .= " GROUP BY a.id ORDER BY a.created_at DESC";

$amendments_stmt = $conn->prepare($amendments_query);
if (!empty($search_term)) {
    $search_param = "%$search_term%";
    $amendments_stmt->bindParam(':search', $search_param);
}
$amendments_stmt->execute();
$amendments = $amendments_stmt->fetchAll();

// Get approval statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft
                FROM amendment_submissions";
$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

// Get AI analytics for amendments (mocked data)
$ai_analytics = [
    'approval_rate' => [
        'value' => 78.5,
        'trend' => 'up',
        'change' => 2.3
    ],
    'avg_processing_time' => [
        'value' => '5.2',
        'unit' => 'days',
        'trend' => 'down',
        'change' => -0.8
    ],
    'risk_assessment' => [
        'level' => 'medium',
        'score' => 62,
        'factors' => ['Legal complexity', 'Multiple reviewers', 'Public hearing required']
    ],
    'recommendations' => [
        'Prioritize amendments with high public impact',
        'Expedite reviews for emergency priority items',
        'Schedule committee reviews for complex amendments'
    ]
];

// Get pending approvals assigned to current user
$my_pending_query = "SELECT COUNT(*) as count FROM amendment_approval_workflow 
                     WHERE assigned_to = :user_id AND status = 'pending'";
$my_pending_stmt = $conn->prepare($my_pending_query);
$my_pending_stmt->bindParam(':user_id', $user_id);
$my_pending_stmt->execute();
$my_pending = $my_pending_stmt->fetch();

// Get recent approval activities
$recent_activities_query = "SELECT aw.*, a.amendment_number, a.title, 
                           u1.first_name as assignee_first, u1.last_name as assignee_last,
                           u2.first_name as actor_first, u2.last_name as actor_last
                           FROM amendment_approval_workflow aw
                           JOIN amendment_submissions a ON aw.amendment_id = a.id
                           LEFT JOIN users u1 ON aw.assigned_to = u1.id
                           LEFT JOIN users u2 ON aw.action_by = u2.id
                           ORDER BY aw.created_at DESC LIMIT 10";
$recent_activities_stmt = $conn->query($recent_activities_query);
$recent_activities = $recent_activities_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Control | QC Amendment Management</title>
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
            --qc-red: #C53030;
            --qc-orange: #ED8936;
            --qc-purple: #805AD5;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.12);
            --shadow-xl: 0 12px 24px rgba(0,0,0,0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --sidebar-width: 320px;
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
        
        /* FIXED MODULE HEADER - IMPROVED */
        .module-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 40px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-xl);
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
            border: 2px solid var(--qc-gold);
        }
        
        .module-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(212, 175, 55, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(212, 175, 55, 0.1) 0%, transparent 50%),
                repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(212, 175, 55, 0.05) 10px, rgba(212, 175, 55, 0.05) 20px);
        }
        
        .module-header-content {
            position: relative;
            z-index: 2;
        }
        
        .module-badge {
            display: inline-block;
            background: rgba(212, 175, 55, 0.2);
            border: 2px solid var(--qc-gold);
            color: var(--qc-gold);
            padding: 12px 30px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: bold;
            letter-spacing: 1.5px;
            margin-bottom: 25px;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .module-title-wrapper {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .module-icon {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid var(--qc-gold);
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--qc-gold);
        }
        
        .module-title {
            flex: 1;
        }
        
        .module-title h1 {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .module-subtitle {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .module-stats {
            display: flex;
            gap: 30px;
            margin-top: 25px;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stat-icon {
            background: rgba(212, 175, 55, 0.2);
            border: 1px solid var(--qc-gold);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--qc-gold);
        }
        
        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
            line-height: 1;
        }
        
        .stat-info p {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        /* ALERTS */
        .alert {
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert-success {
            background: rgba(45, 140, 71, 0.1);
            border: 1px solid var(--qc-green);
            color: var(--qc-green-dark);
        }
        
        .alert-error {
            background: rgba(197, 48, 48, 0.1);
            border: 1px solid var(--qc-red);
            color: var(--qc-red);
        }
        
        .alert-icon {
            font-size: 1.5rem;
        }
        
        /* FILTERS BAR */
        .filters-bar {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .filters-content {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }
        
        .filter-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .filter-control:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        /* AMENDMENTS GRID */
        .amendments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .amendments-grid {
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .amendments-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .amendment-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-light);
        }
        
        .amendment-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }
        
        .amendment-header {
            padding: 25px;
            border-bottom: 1px solid var(--gray-light);
            background: linear-gradient(135deg, var(--off-white) 0%, var(--white) 100%);
        }
        
        .amendment-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 10px;
            line-height: 1.4;
        }
        
        .amendment-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .amendment-number {
            font-weight: 600;
            color: var(--qc-gold);
        }
        
        .amendment-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-draft { background: #e0e7ff; color: #3730a3; }
        .status-under_review { background: #fce7f3; color: #9d174d; }
        
        .amendment-body {
            padding: 25px;
        }
        
        .amendment-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .info-label {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 500;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--gray-dark);
        }
        
        .amendment-description {
            color: var(--gray);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 20px;
            max-height: 100px;
            overflow: hidden;
            position: relative;
        }
        
        .amendment-description::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 40px;
            background: linear-gradient(to bottom, transparent, var(--white));
        }
        
        .voting-summary {
            background: var(--off-white);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .voting-bar {
            display: flex;
            height: 10px;
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
            background: var(--gray-light);
        }
        
        .yes-bar {
            background: var(--qc-green);
            height: 100%;
        }
        
        .no-bar {
            background: var(--qc-red);
            height: 100%;
        }
        
        .voting-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .amendment-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        /* AI ANALYTICS PANEL */
        .ai-analytics-panel {
            background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
            color: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-xl);
            border: 2px solid var(--qc-gold);
        }
        
        .ai-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .ai-icon {
            background: rgba(212, 175, 55, 0.2);
            border: 2px solid var(--qc-gold);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--qc-gold);
        }
        
        .ai-title {
            flex: 1;
        }
        
        .ai-title h3 {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 5px;
        }
        
        .ai-title p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
        }
        
        .ai-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .ai-metric {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: var(--border-radius);
            padding: 20px;
        }
        
        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .metric-title {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .metric-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 5px;
        }
        
        .metric-trend {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }
        
        .trend-up {
            color: #4ade80;
        }
        
        .trend-down {
            color: #f87171;
        }
        
        .ai-recommendations {
            background: rgba(0, 0, 0, 0.2);
            border-radius: var(--border-radius);
            padding: 20px;
            border-left: 4px solid var(--qc-gold);
        }
        
        .recommendations-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--qc-gold);
            margin-bottom: 15px;
        }
        
        .recommendations-list {
            list-style: none;
        }
        
        .recommendations-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.95rem;
        }
        
        .recommendations-list li::before {
            content: '→';
            color: var(--qc-gold);
            font-weight: bold;
        }
        
        /* ACTION BUTTONS */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.85rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
            color: var(--white);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--qc-red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--qc-orange) 0%, #dd6b20 100%);
            color: var(--white);
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-secondary {
            background: var(--gray-light);
            color: var(--gray-dark);
        }
        
        .btn-secondary:hover {
            background: var(--gray);
            color: var(--white);
        }
        
        /* RECENT ACTIVITIES */
        .recent-activities {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .activities-list {
            list-style: none;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
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
            width: 40px;
            height: 40px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-blue);
            font-size: 1rem;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        
        .activity-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        /* MODAL STYLES */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 25px 30px;
            border-top-left-radius: var(--border-radius-lg);
            border-top-right-radius: var(--border-radius-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--qc-gold);
        }
        
        .modal-header h2 {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .modal-form-group {
            margin-bottom: 20px;
        }
        
        .modal-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }
        
        .modal-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s ease;
            background: var(--white);
            min-height: 120px;
            resize: vertical;
        }
        
        .modal-textarea:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
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
        
        /* ANIMATIONS */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .government-footer {
                margin-left: 0;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
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
            
            .module-stats {
                flex-direction: column;
                gap: 20px;
            }
            
            .module-title-wrapper {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .amendment-info {
                grid-template-columns: 1fr;
            }
            
            .amendment-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .filters-content {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .modal-actions {
                flex-direction: column;
            }
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
                    <p>Amendment Approval Control | Module 4: Amendment Management</p>
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
                            <?php echo strtoupper(str_replace('_', ' ', $user_role)); ?>
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
                        <i class="fas fa-check-double"></i> Approval Control
                    </h3>
                    <p class="sidebar-subtitle">Approve or reject amendments</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- MODULE HEADER -->
            <div class="module-header fade-in">
                <div class="module-header-content">
                    <div class="module-badge">APPROVAL CONTROL MODULE</div>
                    
                    <div class="module-title-wrapper">
                        <div class="module-icon">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div class="module-title">
                            <h1>Amendment Approval Control</h1>
                            <p class="module-subtitle">
                                Review, approve, or reject proposed amendments to ordinances and resolutions. 
                                Track voting results, manage approval workflows, and ensure proper legislative procedures.
                            </p>
                        </div>
                    </div>
                    
                    <div class="module-stats">
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['total'] ?? 0; ?></h3>
                                <p>Total Amendments</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['pending'] ?? 0; ?></h3>
                                <p>Pending Review</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $my_pending['count'] ?? 0; ?></h3>
                                <p>Assigned to You</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['approved'] ?? 0; ?></h3>
                                <p>Approved</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-check-circle alert-icon"></i>
                <div><?php echo htmlspecialchars($success_message); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert alert-error fade-in">
                <i class="fas fa-exclamation-circle alert-icon"></i>
                <div><?php echo htmlspecialchars($error_message); ?></div>
            </div>
            <?php endif; ?>

            <!-- AI ANALYTICS PANEL -->
            <div class="ai-analytics-panel fade-in">
                <div class="ai-header">
                    <div class="ai-icon">
                        <i class="fas fa-brain"></i>
                    </div>
                    <div class="ai-title">
                        <h3>AI-Powered Amendment Analytics</h3>
                        <p>Smart recommendations and risk assessment for amendment approvals</p>
                    </div>
                </div>
                
                <div class="ai-metrics">
                    <div class="ai-metric">
                        <div class="metric-header">
                            <div class="metric-title">Approval Rate</div>
                            <div class="metric-trend <?php echo $ai_analytics['approval_rate']['trend'] === 'up' ? 'trend-up' : 'trend-down'; ?>">
                                <i class="fas fa-arrow-<?php echo $ai_analytics['approval_rate']['trend']; ?>"></i>
                                <?php echo $ai_analytics['approval_rate']['change']; ?>%
                            </div>
                        </div>
                        <div class="metric-value"><?php echo $ai_analytics['approval_rate']['value']; ?>%</div>
                        <div style="font-size: 0.85rem; color: rgba(255,255,255,0.7);">
                            Historical approval success rate
                        </div>
                    </div>
                    
                    <div class="ai-metric">
                        <div class="metric-header">
                            <div class="metric-title">Avg Processing Time</div>
                            <div class="metric-trend <?php echo $ai_analytics['avg_processing_time']['trend'] === 'down' ? 'trend-down' : 'trend-up'; ?>">
                                <i class="fas fa-arrow-<?php echo $ai_analytics['avg_processing_time']['trend']; ?>"></i>
                                <?php echo abs($ai_analytics['avg_processing_time']['change']); ?> days
                            </div>
                        </div>
                        <div class="metric-value"><?php echo $ai_analytics['avg_processing_time']['value']; ?> <span style="font-size: 1rem;"><?php echo $ai_analytics['avg_processing_time']['unit']; ?></span></div>
                        <div style="font-size: 0.85rem; color: rgba(255,255,255,0.7);">
                            Average time from submission to decision
                        </div>
                    </div>
                    
                    <div class="ai-metric">
                        <div class="metric-header">
                            <div class="metric-title">Risk Assessment</div>
                            <div class="metric-trend">
                                <span style="padding: 3px 10px; background: rgba(212,175,55,0.3); border-radius: 20px; font-size: 0.8rem;">
                                    <?php echo ucfirst($ai_analytics['risk_assessment']['level']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="metric-value">Score: <?php echo $ai_analytics['risk_assessment']['score']; ?>/100</div>
                        <div style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 5px;">
                            <?php echo implode(', ', array_slice($ai_analytics['risk_assessment']['factors'], 0, 2)); ?>
                        </div>
                    </div>
                </div>
                
                <div class="ai-recommendations">
                    <div class="recommendations-title">
                        <i class="fas fa-lightbulb"></i> AI Recommendations
                    </div>
                    <ul class="recommendations-list">
                        <?php foreach ($ai_analytics['recommendations'] as $recommendation): ?>
                        <li><?php echo htmlspecialchars($recommendation); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- FILTERS BAR -->
            <div class="filters-bar fade-in">
                <form method="GET" action="" class="filters-content">
                    <div class="filter-group">
                        <label class="filter-label">Filter by Status</label>
                        <select name="status" class="filter-control" onchange="this.form.submit()">
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Amendments</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Search Amendments</label>
                        <input type="text" name="search" class="filter-control" 
                               placeholder="Search by title, number, or document..." 
                               value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>
                    
                    <div class="filter-group" style="flex: 0 0 auto;">
                        <label class="filter-label" style="visibility: hidden;">Actions</label>
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="approval_control.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- AMENDMENTS GRID -->
            <div class="amendments-grid fade-in">
                <?php if (empty($amendments)): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                    <i class="fas fa-inbox" style="font-size: 4rem; color: var(--gray-light); margin-bottom: 20px;"></i>
                    <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Amendments Found</h3>
                    <p style="color: var(--gray); max-width: 500px; margin: 0 auto;">
                        <?php echo empty($search_term) ? "No amendments found with status '{$status_filter}'." : "No amendments match your search criteria." ?>
                    </p>
                </div>
                <?php else: ?>
                    <?php foreach ($amendments as $amendment): 
                        $document_title = $amendment['doc_title'] ?? $amendment['res_title'] ?? 'Unknown Document';
                        $document_number = $amendment['ordinance_number'] ?? $amendment['resolution_number'] ?? 'N/A';
                        $total_votes = $amendment['vote_count'] ?? 0;
                        $yes_votes = $amendment['yes_votes'] ?? 0;
                        $no_votes = $amendment['no_votes'] ?? 0;
                        $approval_percentage = $total_votes > 0 ? round(($yes_votes / $total_votes) * 100) : 0;
                    ?>
                    <div class="amendment-card">
                        <div class="amendment-header">
                            <h3 class="amendment-title"><?php echo htmlspecialchars($amendment['title']); ?></h3>
                            <div class="amendment-meta">
                                <span class="amendment-number"><?php echo htmlspecialchars($amendment['amendment_number'] ?? 'AM-' . $amendment['id']); ?></span>
                                <span class="amendment-status status-<?php echo $amendment['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $amendment['status'])); ?>
                                </span>
                                <span><?php echo date('M d, Y', strtotime($amendment['created_at'])); ?></span>
                            </div>
                        </div>
                        
                        <div class="amendment-body">
                            <div class="amendment-info">
                                <div class="info-item">
                                    <span class="info-label">Document:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($document_number); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Priority:</span>
                                    <span class="info-value" style="color: 
                                        <?php echo $amendment['priority'] === 'urgent' ? 'var(--qc-red)' : 
                                               ($amendment['priority'] === 'high' ? 'var(--qc-orange)' : 
                                               ($amendment['priority'] === 'medium' ? 'var(--qc-gold)' : 'var(--qc-green)')); ?>">
                                        <?php echo ucfirst($amendment['priority']); ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Submitted By:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($amendment['submitter_first'] . ' ' . $amendment['submitter_last']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Review Required:</span>
                                    <span class="info-value"><?php echo $amendment['requires_committee_review'] ? 'Yes' : 'No'; ?></span>
                                </div>
                            </div>
                            
                            <div class="amendment-description">
                                <?php echo htmlspecialchars(substr($amendment['description'] ?? 'No description provided.', 0, 150)); ?>...
                            </div>
                            
                            <?php if ($total_votes > 0): ?>
                            <div class="voting-summary">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <span style="font-weight: 600; color: var(--qc-green);">
                                        <i class="fas fa-thumbs-up"></i> Yes: <?php echo $yes_votes; ?>
                                    </span>
                                    <span style="font-weight: 600; color: var(--qc-red);">
                                        <i class="fas fa-thumbs-down"></i> No: <?php echo $no_votes; ?>
                                    </span>
                                </div>
                                <div class="voting-bar">
                                    <div class="yes-bar" style="width: <?php echo $approval_percentage; ?>%;"></div>
                                    <div class="no-bar" style="width: <?php echo 100 - $approval_percentage; ?>%;"></div>
                                </div>
                                <div class="voting-stats">
                                    <span>Total Votes: <?php echo $total_votes; ?></span>
                                    <span>Approval: <?php echo $approval_percentage; ?>%</span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="amendment-actions">
                                <button class="btn btn-primary btn-sm view-amendment-btn" 
                                        data-id="<?php echo $amendment['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($amendment['title']); ?>"
                                        data-number="<?php echo htmlspecialchars($amendment['amendment_number'] ?? 'AM-' . $amendment['id']); ?>">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                                
                                <?php if (in_array($amendment['status'], ['pending', 'under_review'])): ?>
                                <button class="btn btn-success btn-sm approve-btn" 
                                        data-id="<?php echo $amendment['id']; ?>">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn btn-danger btn-sm reject-btn" 
                                        data-id="<?php echo $amendment['id']; ?>">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                                <button class="btn btn-warning btn-sm return-btn" 
                                        data-id="<?php echo $amendment['id']; ?>">
                                    <i class="fas fa-redo"></i> Return
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- RECENT ACTIVITIES -->
            <div class="recent-activities fade-in">
                <div class="section-title" style="display: flex; align-items: center; gap: 12px; margin-bottom: 25px; color: var(--qc-blue); font-size: 1.3rem; font-weight: bold;">
                    <i class="fas fa-history"></i>
                    <h2>Recent Approval Activities</h2>
                </div>
                
                <?php if (empty($recent_activities)): ?>
                <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                    <i class="fas fa-clock" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                    <p>No recent approval activities found.</p>
                </div>
                <?php else: ?>
                <ul class="activities-list">
                    <?php foreach ($recent_activities as $activity): 
                        $action_icon = $activity['status'] === 'approved' ? 'fa-check-circle' : 
                                      ($activity['status'] === 'rejected' ? 'fa-times-circle' : 
                                      ($activity['status'] === 'returned' ? 'fa-redo' : 'fa-clock'));
                        $action_color = $activity['status'] === 'approved' ? 'var(--qc-green)' : 
                                       ($activity['status'] === 'rejected' ? 'var(--qc-red)' : 
                                       ($activity['status'] === 'returned' ? 'var(--qc-orange)' : 'var(--qc-blue)'));
                    ?>
                    <li class="activity-item">
                        <div class="activity-icon" style="color: <?php echo $action_color; ?>;">
                            <i class="fas <?php echo $action_icon; ?>"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">
                                <?php echo htmlspecialchars($activity['actor_first'] . ' ' . $activity['actor_last']); ?> 
                                <?php echo $activity['status']; ?> 
                                <?php echo htmlspecialchars($activity['amendment_number']); ?>
                            </div>
                            <div class="activity-meta">
                                <span><?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></span>
                                <span><?php echo htmlspecialchars($activity['title']); ?></span>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- APPROVAL MODAL -->
    <div class="modal-overlay" id="approvalModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-check-circle"></i> Approve Amendment</h2>
                <button class="modal-close" id="approvalModalClose">&times;</button>
            </div>
            <form id="approvalForm" method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="amendment_id" id="approvalAmendmentId">
                
                <div class="modal-body">
                    <div class="modal-form-group">
                        <label class="modal-label">Amendment Details</label>
                        <div id="approvalDetails" style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); margin-bottom: 15px;">
                            Loading amendment details...
                        </div>
                    </div>
                    
                    <div class="modal-form-group">
                        <label class="modal-label">Approval Comments (Optional)</label>
                        <textarea name="comments" class="modal-textarea" 
                                  placeholder="Add any comments or notes about this approval..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="approvalCancelBtn">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Confirm Approval
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- REJECTION MODAL -->
    <div class="modal-overlay" id="rejectionModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-times-circle"></i> Reject Amendment</h2>
                <button class="modal-close" id="rejectionModalClose">&times;</button>
            </div>
            <form id="rejectionForm" method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="amendment_id" id="rejectionAmendmentId">
                
                <div class="modal-body">
                    <div class="modal-form-group">
                        <label class="modal-label">Amendment Details</label>
                        <div id="rejectionDetails" style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); margin-bottom: 15px;">
                            Loading amendment details...
                        </div>
                    </div>
                    
                    <div class="modal-form-group">
                        <label class="modal-label required">Rejection Reason</label>
                        <textarea name="comments" class="modal-textarea" 
                                  placeholder="Explain why this amendment is being rejected..." required></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="rejectionCancelBtn">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-ban"></i> Confirm Rejection
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- RETURN MODAL -->
    <div class="modal-overlay" id="returnModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-redo"></i> Return for Revision</h2>
                <button class="modal-close" id="returnModalClose">&times;</button>
            </div>
            <form id="returnForm" method="POST">
                <input type="hidden" name="action" value="return">
                <input type="hidden" name="amendment_id" id="returnAmendmentId">
                
                <div class="modal-body">
                    <div class="modal-form-group">
                        <label class="modal-label">Amendment Details</label>
                        <div id="returnDetails" style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); margin-bottom: 15px;">
                            Loading amendment details...
                        </div>
                    </div>
                    
                    <div class="modal-form-group">
                        <label class="modal-label required">Revision Instructions</label>
                        <textarea name="comments" class="modal-textarea" 
                                  placeholder="Provide specific instructions for revision..." required></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="returnCancelBtn">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-paper-plane"></i> Return for Revision
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Approval Control Module</h3>
                    <p>
                        Manage amendment approvals and rejections with comprehensive workflow tracking. 
                        Ensure proper legislative procedures and maintain audit trails for all amendment decisions.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="amendments.php"><i class="fas fa-file-medical-alt"></i> Amendments Dashboard</a></li>
                    <li><a href="comparison.php"><i class="fas fa-code-branch"></i> Change Comparison</a></li>
                    <li><a href="amendment_submission.php"><i class="fas fa-upload"></i> Submit Amendments</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Approval Procedures</a></li>
                    <li><a href="#"><i class="fas fa-gavel"></i> Legislative Guidelines</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Committee Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Approval Feedback</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Amendment Approval Control Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All approval activities are logged and audited.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Approval Control Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // MODAL FUNCTIONALITY
        const approvalModal = document.getElementById('approvalModal');
        const rejectionModal = document.getElementById('rejectionModal');
        const returnModal = document.getElementById('returnModal');
        
        // Approval modal
        document.querySelectorAll('.approve-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const amendmentId = this.getAttribute('data-id');
                const amendmentTitle = this.closest('.amendment-card').querySelector('.amendment-title').textContent;
                const amendmentNumber = this.closest('.amendment-card').querySelector('.amendment-number').textContent;
                
                document.getElementById('approvalAmendmentId').value = amendmentId;
                document.getElementById('approvalDetails').innerHTML = `
                    <strong>${amendmentNumber}</strong><br>
                    ${amendmentTitle}<br>
                    <small>You are about to approve this amendment.</small>
                `;
                
                approvalModal.classList.add('active');
            });
        });
        
        // Rejection modal
        document.querySelectorAll('.reject-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const amendmentId = this.getAttribute('data-id');
                const amendmentTitle = this.closest('.amendment-card').querySelector('.amendment-title').textContent;
                const amendmentNumber = this.closest('.amendment-card').querySelector('.amendment-number').textContent;
                
                document.getElementById('rejectionAmendmentId').value = amendmentId;
                document.getElementById('rejectionDetails').innerHTML = `
                    <strong>${amendmentNumber}</strong><br>
                    ${amendmentTitle}<br>
                    <small>You are about to reject this amendment.</small>
                `;
                
                rejectionModal.classList.add('active');
            });
        });
        
        // Return modal
        document.querySelectorAll('.return-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const amendmentId = this.getAttribute('data-id');
                const amendmentTitle = this.closest('.amendment-card').querySelector('.amendment-title').textContent;
                const amendmentNumber = this.closest('.amendment-card').querySelector('.amendment-number').textContent;
                
                document.getElementById('returnAmendmentId').value = amendmentId;
                document.getElementById('returnDetails').innerHTML = `
                    <strong>${amendmentNumber}</strong><br>
                    ${amendmentTitle}<br>
                    <small>You are about to return this amendment for revision.</small>
                `;
                
                returnModal.classList.add('active');
            });
        });
        
        // View amendment details
        document.querySelectorAll('.view-amendment-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const amendmentId = this.getAttribute('data-id');
                const amendmentTitle = this.getAttribute('data-title');
                const amendmentNumber = this.getAttribute('data-number');
                
                // In a real application, this would fetch amendment details via AJAX
                // For now, show a simple alert
                alert(`Amendment Details:\n\nNumber: ${amendmentNumber}\nTitle: ${amendmentTitle}\n\nFull details would be loaded here with AJAX.`);
            });
        });
        
        // Close modals
        function closeAllModals() {
            approvalModal.classList.remove('active');
            rejectionModal.classList.remove('active');
            returnModal.classList.remove('active');
        }
        
        document.getElementById('approvalModalClose').addEventListener('click', closeAllModals);
        document.getElementById('approvalCancelBtn').addEventListener('click', closeAllModals);
        
        document.getElementById('rejectionModalClose').addEventListener('click', closeAllModals);
        document.getElementById('rejectionCancelBtn').addEventListener('click', closeAllModals);
        
        document.getElementById('returnModalClose').addEventListener('click', closeAllModals);
        document.getElementById('returnCancelBtn').addEventListener('click', closeAllModals);
        
        // Close modals when clicking outside
        [approvalModal, rejectionModal, returnModal].forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeAllModals();
                }
            });
        });
        
        // Form submissions
        document.getElementById('approvalForm').addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to approve this amendment?')) {
                e.preventDefault();
            }
        });
        
        document.getElementById('rejectionForm').addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to reject this amendment?')) {
                e.preventDefault();
            }
        });
        
        document.getElementById('returnForm').addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to return this amendment for revision?')) {
                e.preventDefault();
            }
        });
        
        // Add animation to elements
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
        
        // Observe amendment cards and other sections
        document.querySelectorAll('.amendment-card, .recent-activities').forEach(section => {
            observer.observe(section);
        });
        
        // Auto-refresh page every 60 seconds to check for new amendments
        let refreshTimer;
        function startAutoRefresh() {
            refreshTimer = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    console.log('Auto-refreshing amendment list...');
                    // In a real application, you might use AJAX to refresh data
                    // For now, we'll just log to console
                }
            }, 60000);
        }
        
        // Start auto-refresh
        startAutoRefresh();
        
        // Stop auto-refresh when page is not visible
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                clearInterval(refreshTimer);
            } else {
                startAutoRefresh();
            }
        });
        
        // Add confirmation for leaving page with unsaved changes
        let formChanged = false;
        
        document.querySelectorAll('.modal-textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                formChanged = true;
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes in a modal. Are you sure you want to leave?';
            }
        });
        
        // Reset form changed flag when modals are closed
        document.querySelectorAll('.modal-close, .btn-secondary').forEach(btn => {
            btn.addEventListener('click', function() {
                formChanged = false;
            });
        });
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape closes modals
            if (e.key === 'Escape') {
                closeAllModals();
            }
            
            // Ctrl+F focuses search (when not in textarea)
            if (e.ctrlKey && e.key === 'f' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'INPUT') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
        });
    </script>
</body>
</html>