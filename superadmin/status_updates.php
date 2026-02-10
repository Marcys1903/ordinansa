<?php
// status_updates.php - Status Updates Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to update statuses
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

// Handle form submissions
$success_message = '';
$error_message = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $new_status = $_POST['new_status'];
        $status_notes = $_POST['status_notes'] ?? '';
        $next_step = $_POST['next_step'] ?? '';
        $target_date = $_POST['target_date'] ?? null;
        
        // Validate input
        if (empty($document_id) || empty($document_type) || empty($new_status)) {
            throw new Exception("All required fields must be filled.");
        }
        
        // Get current document information
        if ($document_type === 'ordinance') {
            $doc_query = "SELECT ordinance_number, title, status FROM ordinances WHERE id = :id";
        } else {
            $doc_query = "SELECT resolution_number, title, status FROM resolutions WHERE id = :id";
        }
        
        $stmt = $conn->prepare($doc_query);
        $stmt->bindParam(':id', $document_id);
        $stmt->execute();
        $document = $stmt->fetch();
        
        if (!$document) {
            throw new Exception("Document not found.");
        }
        
        $old_status = $document['status'];
        $doc_number = $document_type === 'ordinance' ? $document['ordinance_number'] : $document['resolution_number'];
        
        // Check if status can be updated
        if (!isStatusTransitionValid($old_status, $new_status, $user_role)) {
            throw new Exception("Invalid status transition from '{$old_status}' to '{$new_status}' for your role.");
        }
        
        $conn->beginTransaction();
        
        // Update document status
        if ($document_type === 'ordinance') {
            $update_query = "UPDATE ordinances SET status = :status, updated_at = NOW() WHERE id = :id";
        } else {
            $update_query = "UPDATE resolutions SET status = :status, updated_at = NOW() WHERE id = :id";
        }
        
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':status', $new_status);
        $stmt->bindParam(':id', $document_id);
        $stmt->execute();
        
        // If approved, set approved_by
        if ($new_status === 'approved') {
            if ($document_type === 'ordinance') {
                $approve_query = "UPDATE ordinances SET approved_by = :user_id WHERE id = :id";
            } else {
                $approve_query = "UPDATE resolutions SET approved_by = :user_id WHERE id = :id";
            }
            $stmt = $conn->prepare($approve_query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':id', $document_id);
            $stmt->execute();
        }
        
        // Record status history (check if table exists first)
        $history_query = "INSERT INTO status_history (document_id, document_type, old_status, new_status, 
                         notes, changed_by, next_step, target_date) 
                         VALUES (:doc_id, :doc_type, :old_status, :new_status, :notes, :changed_by, 
                         :next_step, :target_date)";
        $stmt = $conn->prepare($history_query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->bindParam(':doc_type', $document_type);
        $stmt->bindParam(':old_status', $old_status);
        $stmt->bindParam(':new_status', $new_status);
        $stmt->bindParam(':notes', $status_notes);
        $stmt->bindParam(':changed_by', $user_id);
        $stmt->bindParam(':next_step', $next_step);
        $stmt->bindParam(':target_date', $target_date);
        $stmt->execute();
        
        // Log the action
        $action_description = "Updated status for {$doc_number} from '{$old_status}' to '{$new_status}'";
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'STATUS_UPDATE', :description, :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':description', $action_description);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $conn->commit();
        
        $success_message = "Status updated successfully! {$doc_number} is now '{$new_status}'";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = "Error updating status: " . $e->getMessage();
    }
}

// Handle bulk status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    try {
        $document_ids = $_POST['bulk_document_ids'] ?? [];
        $bulk_status = $_POST['bulk_status'];
        $bulk_notes = $_POST['bulk_notes'] ?? '';
        
        if (empty($document_ids)) {
            throw new Exception("No documents selected for bulk update.");
        }
        
        if (empty($bulk_status)) {
            throw new Exception("Please select a status for bulk update.");
        }
        
        $conn->beginTransaction();
        $updated_count = 0;
        
        foreach ($document_ids as $doc_info) {
            list($doc_id, $doc_type) = explode('|', $doc_info);
            
            // Get current document information
            if ($doc_type === 'ordinance') {
                $doc_query = "SELECT ordinance_number, status FROM ordinances WHERE id = :id";
            } else {
                $doc_query = "SELECT resolution_number, status FROM resolutions WHERE id = :id";
            }
            
            $stmt = $conn->prepare($doc_query);
            $stmt->bindParam(':id', $doc_id);
            $stmt->execute();
            $document = $stmt->fetch();
            
            if ($document) {
                $old_status = $document['status'];
                $doc_number = $doc_type === 'ordinance' ? $document['ordinance_number'] : $document['resolution_number'];
                
                // Check if status can be updated
                if (isStatusTransitionValid($old_status, $bulk_status, $user_role)) {
                    // Update document status
                    if ($doc_type === 'ordinance') {
                        $update_query = "UPDATE ordinances SET status = :status, updated_at = NOW() WHERE id = :id";
                    } else {
                        $update_query = "UPDATE resolutions SET status = :status, updated_at = NOW() WHERE id = :id";
                    }
                    
                    $stmt = $conn->prepare($update_query);
                    $stmt->bindParam(':status', $bulk_status);
                    $stmt->bindParam(':id', $doc_id);
                    $stmt->execute();
                    
                    // Record status history
                    $history_query = "INSERT INTO status_history (document_id, document_type, old_status, new_status, 
                                     notes, changed_by) 
                                     VALUES (:doc_id, :doc_type, :old_status, :new_status, :notes, :changed_by)";
                    $stmt = $conn->prepare($history_query);
                    $stmt->bindParam(':doc_id', $doc_id);
                    $stmt->bindParam(':doc_type', $doc_type);
                    $stmt->bindParam(':old_status', $old_status);
                    $stmt->bindParam(':new_status', $bulk_status);
                    $stmt->bindParam(':notes', $bulk_notes);
                    $stmt->bindParam(':changed_by', $user_id);
                    $stmt->execute();
                    
                    $updated_count++;
                }
            }
        }
        
        // Log the action
        $action_description = "Bulk updated {$updated_count} documents to '{$bulk_status}'";
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'BULK_STATUS_UPDATE', :description, :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':description', $action_description);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $conn->commit();
        
        $success_message = "Bulk update completed! {$updated_count} document(s) updated to '{$bulk_status}'";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = "Error in bulk update: " . $e->getMessage();
    }
}

// Function to check if status transition is valid based on user role
function isStatusTransitionValid($old_status, $new_status, $user_role) {
    $valid_transitions = [
        'draft' => ['pending', 'cancelled'],
        'pending' => ['under_review', 'rejected', 'cancelled'],
        'under_review' => ['committee_review', 'amended', 'rejected'],
        'committee_review' => ['for_voting', 'amended', 'rejected'],
        'for_voting' => ['approved', 'rejected', 'postponed'],
        'approved' => ['implemented', 'amended'],
        'implemented' => ['archived'],
        'amended' => ['pending', 'under_review'],
        'postponed' => ['for_voting', 'cancelled'],
        'rejected' => ['draft', 'archived'],
        'cancelled' => ['archived']
    ];
    
    // Check if transition exists
    if (!isset($valid_transitions[$old_status]) || !in_array($new_status, $valid_transitions[$old_status])) {
        return false;
    }
    
    // Role-based restrictions
    $admin_only_statuses = ['approved', 'implemented', 'archived'];
    $councilor_restricted = ['approved']; // Councilors can't approve their own documents
    
    if (in_array($new_status, $admin_only_statuses) && !in_array($user_role, ['super_admin', 'admin'])) {
        return false;
    }
    
    return true;
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$filter_committee = $_GET['committee'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build filter conditions
$where_conditions = [];
$params = [];

// Status filter
if ($filter_status !== 'all') {
    $where_conditions[] = "d.status = :status";
    $params[':status'] = $filter_status;
}

// Type filter
if ($filter_type !== 'all') {
    $where_conditions[] = "d.document_type = :doc_type";
    $params[':doc_type'] = $filter_type;
}

// Search query
if (!empty($search_query)) {
    $where_conditions[] = "(d.title LIKE :search OR d.document_number LIKE :search OR d.description LIKE :search)";
    $params[':search'] = "%{$search_query}%";
}

// Role-based filtering
if ($user_role === 'councilor') {
    // Councilors can only see documents they created or are assigned to
    $where_conditions[] = "(d.created_by = :user_id OR da.user_id = :user_id)";
    $params[':user_id'] = $user_id;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get documents for listing (with their current status) - FIXED QUERY
$documents_query = "
    SELECT 
        d.id,
        d.document_type,
        d.document_number,
        d.title,
        d.description,
        d.status,
        d.created_at,
        d.created_by,
        d.priority_level,
        u.first_name,
        u.last_name,
        (
            SELECT c.committee_name 
            FROM document_committees dc 
            LEFT JOIN committees c ON dc.committee_id = c.id 
            WHERE dc.document_id = d.id AND dc.document_type = d.document_type 
            LIMIT 1
        ) as committee_name,
        (
            SELECT new_status 
            FROM status_history sh 
            WHERE sh.document_id = d.id AND sh.document_type = d.document_type 
            ORDER BY sh.changed_at DESC 
            LIMIT 1
        ) as last_status_change,
        (
            SELECT CONCAT(u2.first_name, ' ', u2.last_name) 
            FROM status_history sh2 
            LEFT JOIN users u2 ON sh2.changed_by = u2.id 
            WHERE sh2.document_id = d.id AND sh2.document_type = d.document_type 
            ORDER BY sh2.changed_at DESC 
            LIMIT 1
        ) as last_changed_by
    FROM (
        SELECT id, 'ordinance' as document_type, ordinance_number as document_number, 
               title, description, status, created_at, created_by, NULL as priority_level
        FROM ordinances
        UNION ALL
        SELECT id, 'resolution' as document_type, resolution_number as document_number, 
               title, description, status, created_at, created_by, NULL as priority_level
        FROM resolutions
    ) d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN document_authors da ON d.id = da.document_id AND d.document_type = da.document_type
    {$where_clause}
    GROUP BY d.id, d.document_type
    ORDER BY 
        CASE d.status 
            WHEN 'draft' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'under_review' THEN 3
            WHEN 'committee_review' THEN 4
            WHEN 'for_voting' THEN 5
            WHEN 'approved' THEN 6
            WHEN 'implemented' THEN 7
            WHEN 'rejected' THEN 8
            WHEN 'amended' THEN 9
            WHEN 'postponed' THEN 10
            WHEN 'cancelled' THEN 11
            WHEN 'archived' THEN 12
            ELSE 13
        END,
        d.created_at DESC
    LIMIT 100";

$stmt = $conn->prepare($documents_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$documents = $stmt->fetchAll();

// Get status history for selected document
$selected_doc_id = $_GET['doc_id'] ?? null;
$selected_doc_type = $_GET['doc_type'] ?? null;
$status_history = [];

if ($selected_doc_id && $selected_doc_type) {
    // Check if status_history table exists first
    $table_exists = false;
    try {
        $check_table = $conn->query("SELECT 1 FROM status_history LIMIT 1");
        $table_exists = true;
    } catch (PDOException $e) {
        $table_exists = false;
    }
    
    if ($table_exists) {
        $history_query = "
            SELECT sh.*, u.first_name, u.last_name, u.role
            FROM status_history sh
            LEFT JOIN users u ON sh.changed_by = u.id
            WHERE sh.document_id = :doc_id AND sh.document_type = :doc_type
            ORDER BY sh.changed_at DESC";
        
        $stmt = $conn->prepare($history_query);
        $stmt->bindParam(':doc_id', $selected_doc_id);
        $stmt->bindParam(':doc_type', $selected_doc_type);
        $stmt->execute();
        $status_history = $stmt->fetchAll();
    }
}

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_documents,
        SUM(CASE WHEN status IN ('draft', 'pending', 'under_review', 'committee_review') THEN 1 ELSE 0 END) as pending_documents,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_documents,
        SUM(CASE WHEN status = 'implemented' THEN 1 ELSE 0 END) as implemented_documents,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_documents
    FROM (
        SELECT status FROM ordinances
        UNION ALL
        SELECT status FROM resolutions
    ) documents";

$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

// Get available statuses based on user role
$available_statuses = getAvailableStatuses($user_role);

function getAvailableStatuses($user_role) {
    $all_statuses = [
        'draft' => ['label' => 'Draft', 'color' => '#6B7280', 'icon' => 'fa-edit'],
        'pending' => ['label' => 'Pending', 'color' => '#3B82F6', 'icon' => 'fa-clock'],
        'under_review' => ['label' => 'Under Review', 'color' => '#8B5CF6', 'icon' => 'fa-search'],
        'committee_review' => ['label' => 'Committee Review', 'color' => '#EC4899', 'icon' => 'fa-users'],
        'for_voting' => ['label' => 'For Voting', 'color' => '#F59E0B', 'icon' => 'fa-vote-yea'],
        'approved' => ['label' => 'Approved', 'color' => '#10B981', 'icon' => 'fa-check-circle'],
        'implemented' => ['label' => 'Implemented', 'color' => '#059669', 'icon' => 'fa-check-double'],
        'rejected' => ['label' => 'Rejected', 'color' => '#EF4444', 'icon' => 'fa-times-circle'],
        'amended' => ['label' => 'Amended', 'color' => '#8B5CF6', 'icon' => 'fa-edit'],
        'postponed' => ['label' => 'Postponed', 'color' => '#F59E0B', 'icon' => 'fa-calendar-times'],
        'cancelled' => ['label' => 'Cancelled', 'color' => '#6B7280', 'icon' => 'fa-ban'],
        'archived' => ['label' => 'Archived', 'color' => '#6B7280', 'icon' => 'fa-archive']
    ];
    
    // Filter based on user role
    if ($user_role === 'councilor') {
        unset($all_statuses['approved']);
        unset($all_statuses['implemented']);
        unset($all_statuses['archived']);
    }
    
    return $all_statuses;
}

// Get committees for filter
$committees_query = "SELECT id, committee_name FROM committees WHERE is_active = 1 ORDER BY committee_name";
$committees_stmt = $conn->query($committees_query);
$committees = $committees_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Updates | QC Ordinance Tracker</title>
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
            --yellow: #D97706;
            --purple: #8B5CF6;
            --pink: #EC4899;
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
            max-width: 800px;
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
        
        /* FILTERS SECTION */
        .filters-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
        }
        
        .filter-select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            background: var(--white);
            color: var(--gray-dark);
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        .search-box {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding-top: 15px;
            border-top: 1px solid var(--gray-light);
        }
        
        /* STATUS BADGES */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-draft { background: #f3f4f6; color: #374151; }
        .status-pending { background: #dbeafe; color: #1e40af; }
        .status-under_review { background: #f3e8ff; color: #7c3aed; }
        .status-committee_review { background: #fce7f3; color: #be185d; }
        .status-for_voting { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-implemented { background: #a7f3d0; color: #047857; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-amended { background: #e0e7ff; color: #4f46e5; }
        .status-postponed { background: #fef3c7; color: #92400e; }
        .status-cancelled { background: #f3f4f6; color: #6b7280; }
        .status-archived { background: #e5e7eb; color: #4b5563; }
        
        /* DOCUMENTS TABLE */
        .documents-table-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .table-header h2 {
            color: var(--qc-blue);
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .table-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .documents-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .documents-table th {
            background: var(--off-white);
            color: var(--qc-blue);
            font-weight: 600;
            text-align: left;
            padding: 15px;
            border-bottom: 2px solid var(--gray-light);
            white-space: nowrap;
        }
        
        .documents-table td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: top;
        }
        
        .documents-table tbody tr {
            transition: all 0.3s ease;
        }
        
        .documents-table tbody tr:hover {
            background: var(--off-white);
        }
        
        .document-title {
            font-weight: 600;
            color: var(--qc-blue);
            margin-bottom: 5px;
            line-height: 1.4;
        }
        
        .document-meta {
            font-size: 0.85rem;
            color: var(--gray);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 5px;
        }
        
        .document-type {
            display: inline-block;
            padding: 3px 8px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border-radius: 4px;
            font-size: 0.8rem;
        }
        
        .type-ordinance { background: #dbeafe; color: #1e40af; }
        .type-resolution { background: #f3e8ff; color: #7c3aed; }
        
        .document-actions {
            display: flex;
            gap: 8px;
        }
        
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }
        
        .bulk-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--qc-blue);
        }
        
        /* STATUS UPDATE FORM */
        .status-update-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--red);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .status-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .status-option {
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .status-option:hover {
            border-color: var(--qc-blue);
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }
        
        .status-option.selected {
            border-color: var(--qc-gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .status-option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .status-option.disabled:hover {
            transform: none;
            border-color: var(--gray-light);
            box-shadow: none;
        }
        
        .status-radio {
            display: none;
        }
        
        .status-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        
        .status-name {
            font-weight: bold;
            color: var(--gray-dark);
            margin-bottom: 5px;
        }
        
        .status-description {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid var(--gray-light);
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
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
        
        .btn-success {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
            color: var(--white);
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        /* BULK UPDATE SECTION */
        .bulk-update-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .bulk-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--qc-gold);
        }
        
        .bulk-icon {
            background: rgba(212, 175, 55, 0.1);
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
        
        .bulk-title {
            flex: 1;
        }
        
        .bulk-title h3 {
            color: var(--qc-blue);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }
        
        .bulk-title p {
            color: var(--gray);
            font-size: 0.95rem;
        }
        
        .selected-count {
            background: var(--qc-blue);
            color: var(--white);
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 1.2rem;
            box-shadow: var(--shadow-sm);
        }
        
        /* STATUS HISTORY */
        .status-history-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .history-timeline {
            position: relative;
            padding-left: 30px;
            margin-top: 20px;
        }
        
        .history-timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--gray-light);
        }
        
        .history-item {
            position: relative;
            padding: 20px;
            margin-bottom: 20px;
            background: var(--off-white);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--qc-blue);
        }
        
        .history-item::before {
            content: '';
            position: absolute;
            left: -33px;
            top: 25px;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: var(--qc-blue);
            border: 3px solid var(--white);
            box-shadow: 0 0 0 3px var(--qc-blue-light);
        }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .history-status {
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        .history-date {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .history-user {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .user-avatar-small {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--qc-gold);
            color: var(--qc-blue-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.8rem;
        }
        
        .history-notes {
            color: var(--gray-dark);
            font-size: 0.95rem;
            line-height: 1.6;
            padding: 10px;
            background: var(--white);
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            margin-top: 10px;
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
            border: 1px solid var(--red);
            color: var(--red);
        }
        
        .alert-icon {
            font-size: 1.5rem;
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
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .status-options {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .table-actions {
                width: 100%;
                justify-content: space-between;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .status-options {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .document-meta {
                flex-direction: column;
                gap: 5px;
            }
            
            .document-actions {
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
                    <p>Status Updates Module | Track & Update Document Status</p>
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
                        <i class="fas fa-chart-line"></i> Status Tracking
                    </h3>
                    <p class="sidebar-subtitle">Status Updates Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="status-updates-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">STATUS UPDATES MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-sync-alt"></i>
                            </div>
                            <div class="module-title">
                                <h1>Document Status Updates</h1>
                                <p class="module-subtitle">
                                    Track, monitor, and update the status of ordinances and resolutions. 
                                    View status history, update document progression, and manage workflow transitions.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats['total_documents']; ?></h3>
                                    <p>Total Documents</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats['pending_documents']; ?></h3>
                                    <p>Pending Review</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats['approved_documents']; ?></h3>
                                    <p>Approved</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-check-double"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats['implemented_documents']; ?></h3>
                                    <p>Implemented</p>
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

                <!-- FILTERS SECTION -->
                <div class="filters-section fade-in">
                    <h3 style="color: var(--qc-blue); margin-bottom: 20px; font-size: 1.3rem;">
                        <i class="fas fa-filter"></i> Filter Documents
                    </h3>
                    
                    <form method="GET" action="" id="filterForm">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label class="filter-label">Document Type</label>
                                <select name="type" class="filter-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="ordinance" <?php echo $filter_type === 'ordinance' ? 'selected' : ''; ?>>Ordinance</option>
                                    <option value="resolution" <?php echo $filter_type === 'resolution' ? 'selected' : ''; ?>>Resolution</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <?php foreach ($available_statuses as $status => $status_info): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $filter_status === $status ? 'selected' : ''; ?>>
                                        <?php echo $status_info['label']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Committee</label>
                                <select name="committee" class="filter-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_committee === 'all' ? 'selected' : ''; ?>>All Committees</option>
                                    <?php foreach ($committees as $committee): ?>
                                    <option value="<?php echo $committee['id']; ?>" <?php echo $filter_committee == $committee['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($committee['committee_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Priority Level</label>
                                <select name="priority" class="filter-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                    <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                    <option value="emergency" <?php echo $filter_priority === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" class="search-input" 
                                   placeholder="Search by title, document number, or description..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <a href="status_updates.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset Filters
                            </a>
                        </div>
                    </form>
                </div>

                <!-- BULK UPDATE SECTION -->
                <div class="bulk-update-container fade-in">
                    <div class="bulk-header">
                        <div class="bulk-icon">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <div class="bulk-title">
                            <h3>Bulk Status Update</h3>
                            <p>Update multiple documents to the same status at once</p>
                        </div>
                        <div class="selected-count" id="selectedCount">0 Selected</div>
                    </div>
                    
                    <form method="POST" action="" id="bulkUpdateForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">New Status for Selected Documents</label>
                                <select name="bulk_status" class="form-control" required>
                                    <option value="">Select Status</option>
                                    <?php foreach ($available_statuses as $status => $status_info): ?>
                                    <option value="<?php echo $status; ?>">
                                        <?php echo $status_info['label']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Notes (Optional)</label>
                                <textarea name="bulk_notes" class="form-control" 
                                          placeholder="Add notes for this bulk status update..." 
                                          rows="2"></textarea>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="selectAllDocuments()">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="deselectAllDocuments()">
                                <i class="fas fa-times-circle"></i> Deselect All
                            </button>
                            <button type="submit" name="bulk_update" class="btn btn-success" id="bulkUpdateBtn" disabled>
                                <i class="fas fa-bolt"></i> Update Selected Documents
                            </button>
                        </div>
                        
                        <!-- Hidden field for selected documents -->
                        <div id="bulkDocumentIds"></div>
                    </form>
                </div>

                <!-- DOCUMENTS TABLE -->
                <div class="documents-table-container fade-in">
                    <div class="table-header">
                        <h2>
                            <i class="fas fa-list"></i> 
                            Documents for Status Updates 
                            <span style="color: var(--gray); font-size: 1rem; margin-left: 10px;">
                                (<?php echo count($documents); ?> found)
                            </span>
                        </h2>
                        <div class="table-actions">
                            <button type="button" class="btn btn-secondary" onclick="refreshTable()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <a href="status_updates.php?export=csv" class="btn btn-secondary">
                                <i class="fas fa-download"></i> Export CSV
                            </a>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th class="checkbox-cell">
                                        <input type="checkbox" id="selectAllCheckbox" class="bulk-checkbox">
                                    </th>
                                    <th>Document</th>
                                    <th>Current Status</th>
                                    <th>Created By</th>
                                    <th>Date Created</th>
                                    <th>Last Update</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($documents)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px 20px;">
                                        <i class="fas fa-inbox" style="font-size: 3rem; color: var(--gray-light); margin-bottom: 15px;"></i>
                                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Documents Found</h3>
                                        <p style="color: var(--gray);">Try adjusting your filters or search criteria.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($documents as $doc): 
                                    $status_info = $available_statuses[$doc['status']] ?? 
                                                  ['label' => ucfirst($doc['status']), 'color' => '#6B7280', 'icon' => 'fa-circle'];
                                    $doc_type_class = $doc['document_type'] === 'ordinance' ? 'type-ordinance' : 'type-resolution';
                                    $is_selected = ($selected_doc_id == $doc['id'] && $selected_doc_type == $doc['document_type']);
                                ?>
                                <tr class="<?php echo $is_selected ? 'selected-row' : ''; ?>" 
                                    data-doc-id="<?php echo $doc['id']; ?>"
                                    data-doc-type="<?php echo $doc['document_type']; ?>"
                                    data-doc-number="<?php echo htmlspecialchars($doc['document_number']); ?>"
                                    data-doc-title="<?php echo htmlspecialchars($doc['title']); ?>"
                                    data-current-status="<?php echo $doc['status']; ?>">
                                    <td class="checkbox-cell">
                                        <input type="checkbox" class="bulk-checkbox document-checkbox" 
                                               data-doc-info="<?php echo $doc['id'] . '|' . $doc['document_type']; ?>">
                                    </td>
                                    <td>
                                        <div class="document-title">
                                            <?php echo htmlspecialchars($doc['title']); ?>
                                        </div>
                                        <div class="document-meta">
                                            <span class="document-type <?php echo $doc_type_class; ?>">
                                                <i class="fas <?php echo $doc['document_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature'; ?>"></i>
                                                <?php echo ucfirst($doc['document_type']); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-hashtag"></i>
                                                <?php echo htmlspecialchars($doc['document_number']); ?>
                                            </span>
                                            <?php if ($doc['committee_name']): ?>
                                            <span>
                                                <i class="fas fa-users"></i>
                                                <?php echo htmlspecialchars($doc['committee_name']); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($doc['description']): ?>
                                        <div style="color: var(--gray); font-size: 0.9rem; margin-top: 8px;">
                                            <?php echo htmlspecialchars(substr($doc['description'], 0, 100)); ?>
                                            <?php echo strlen($doc['description']) > 100 ? '...' : ''; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $doc['status']; ?>">
                                            <i class="fas <?php echo $status_info['icon']; ?>"></i>
                                            <?php echo $status_info['label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: var(--qc-blue);">
                                            <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?>
                                        </div>
                                        <div style="color: var(--gray); font-size: 0.85rem;">
                                            <?php echo date('M d, Y', strtotime($doc['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($doc['created_at'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($doc['last_changed_by']): ?>
                                        <div style="color: var(--gray); font-size: 0.85rem;">
                                            By: <?php echo htmlspecialchars($doc['last_changed_by']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <div style="color: var(--gray); font-size: 0.85rem;">
                                            <?php echo $doc['last_status_change'] ? 
                                                   $available_statuses[$doc['last_status_change']]['label'] ?? ucfirst($doc['last_status_change']) : 
                                                   'No changes'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="document-actions">
                                            <button type="button" class="btn btn-primary btn-sm update-status-btn"
                                                    data-doc-id="<?php echo $doc['id']; ?>"
                                                    data-doc-type="<?php echo $doc['document_type']; ?>"
                                                    data-doc-number="<?php echo htmlspecialchars($doc['document_number']); ?>"
                                                    data-doc-title="<?php echo htmlspecialchars($doc['title']); ?>"
                                                    data-current-status="<?php echo $doc['status']; ?>">
                                                <i class="fas fa-edit"></i> Update
                                            </button>
                                            <a href="status_updates.php?doc_id=<?php echo $doc['id']; ?>&doc_type=<?php echo $doc['document_type']; ?>" 
                                               class="btn btn-secondary btn-sm">
                                                <i class="fas fa-history"></i> History
                                            </a>
                                            <a href="view_document.php?type=<?php echo $doc['document_type']; ?>&id=<?php echo $doc['id']; ?>" 
                                               class="btn btn-secondary btn-sm" target="_blank">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- STATUS UPDATE FORM (Shows when a document is selected) -->
                <?php if ($selected_doc_id && $selected_doc_type): 
                    // Find the selected document
                    $selected_doc = null;
                    foreach ($documents as $doc) {
                        if ($doc['id'] == $selected_doc_id && $doc['document_type'] == $selected_doc_type) {
                            $selected_doc = $doc;
                            break;
                        }
                    }
                    
                    if ($selected_doc):
                ?>
                <div class="status-update-container fade-in" id="statusUpdateForm">
                    <h3 style="color: var(--qc-blue); margin-bottom: 20px; font-size: 1.5rem;">
                        <i class="fas fa-edit"></i> Update Status for: 
                        <span style="color: var(--qc-gold);"><?php echo htmlspecialchars($selected_doc['document_number']); ?></span>
                    </h3>
                    
                    <div style="background: var(--off-white); padding: 20px; border-radius: var(--border-radius); margin-bottom: 25px; border-left: 4px solid var(--qc-blue);">
                        <h4 style="color: var(--qc-blue); margin-bottom: 10px;">Document Information</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div>
                                <strong>Title:</strong><br>
                                <?php echo htmlspecialchars($selected_doc['title']); ?>
                            </div>
                            <div>
                                <strong>Current Status:</strong><br>
                                <span class="status-badge status-<?php echo $selected_doc['status']; ?>">
                                    <i class="fas <?php echo $available_statuses[$selected_doc['status']]['icon'] ?? 'fa-circle'; ?>"></i>
                                    <?php echo $available_statuses[$selected_doc['status']]['label'] ?? ucfirst($selected_doc['status']); ?>
                                </span>
                            </div>
                            <div>
                                <strong>Type:</strong><br>
                                <?php echo ucfirst($selected_doc['document_type']); ?>
                            </div>
                            <div>
                                <strong>Created By:</strong><br>
                                <?php echo htmlspecialchars($selected_doc['first_name'] . ' ' . $selected_doc['last_name']); ?>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="document_id" value="<?php echo $selected_doc_id; ?>">
                        <input type="hidden" name="document_type" value="<?php echo $selected_doc_type; ?>">
                        
                        <div class="form-group">
                            <label class="form-label required">Select New Status</label>
                            <div class="status-options" id="statusOptions">
                                <?php foreach ($available_statuses as $status => $status_info): 
                                    $is_current = $status === $selected_doc['status'];
                                    $can_transition = isStatusTransitionValid($selected_doc['status'], $status, $user_role);
                                ?>
                                <div class="status-option <?php echo $is_current ? 'current' : ''; ?> <?php echo !$can_transition ? 'disabled' : ''; ?>"
                                     style="border-color: <?php echo $status_info['color']; ?>30;"
                                     onclick="<?php echo $can_transition ? "selectStatus('{$status}')" : ''; ?>">
                                    <div class="status-icon" style="color: <?php echo $status_info['color']; ?>;">
                                        <i class="fas <?php echo $status_info['icon']; ?>"></i>
                                    </div>
                                    <div class="status-name" style="color: <?php echo $status_info['color']; ?>;">
                                        <?php echo $status_info['label']; ?>
                                        <?php if ($is_current): ?>
                                        <span style="color: var(--gray); font-size: 0.8rem;">(Current)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="status-description">
                                        <?php if (!$can_transition && !$is_current): ?>
                                        <span style="color: var(--red); font-size: 0.8rem;">Invalid transition</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="radio" name="new_status" value="<?php echo $status; ?>" 
                                           class="status-radio" <?php echo !$can_transition ? 'disabled' : ''; ?>>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Next Step/Requirement</label>
                                <select name="next_step" class="form-control">
                                    <option value="">Select Next Step</option>
                                    <option value="committee_review">Send to Committee Review</option>
                                    <option value="legal_review">Send to Legal Review</option>
                                    <option value="public_hearing">Schedule Public Hearing</option>
                                    <option value="voting">Schedule for Voting</option>
                                    <option value="mayor_signature">Send to Mayor for Signature</option>
                                    <option value="publication">Schedule Publication</option>
                                    <option value="implementation">Begin Implementation</option>
                                    <option value="monitoring">Start Monitoring</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Target Date</label>
                                <input type="date" name="target_date" class="form-control" 
                                       min="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status Update Notes</label>
                            <textarea name="status_notes" class="form-control" 
                                      placeholder="Enter detailed notes about this status change, reasons, or instructions..." 
                                      rows="4" required></textarea>
                            <small style="color: var(--gray); display: block; margin-top: 5px;">
                                Explain why the status is being changed and any important details.
                            </small>
                        </div>
                        
                        <div class="form-actions">
                            <a href="status_updates.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" name="update_status" class="btn btn-success">
                                <i class="fas fa-check"></i> Update Status
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; endif; ?>

                <!-- STATUS HISTORY -->
                <?php if ($selected_doc_id && $selected_doc_type && !empty($status_history)): ?>
                <div class="status-history-container fade-in">
                    <h3 style="color: var(--qc-blue); margin-bottom: 20px; font-size: 1.5rem;">
                        <i class="fas fa-history"></i> Status History for: 
                        <span style="color: var(--qc-gold);"><?php echo htmlspecialchars($selected_doc['document_number'] ?? ''); ?></span>
                    </h3>
                    
                    <div class="history-timeline">
                        <?php foreach ($status_history as $history): 
                            $old_status_info = $available_statuses[$history['old_status']] ?? 
                                             ['label' => ucfirst($history['old_status']), 'color' => '#6B7280'];
                            $new_status_info = $available_statuses[$history['new_status']] ?? 
                                             ['label' => ucfirst($history['new_status']), 'color' => '#6B7280'];
                        ?>
                        <div class="history-item">
                            <div class="history-header">
                                <div class="history-status">
                                    <span style="color: <?php echo $old_status_info['color']; ?>;">
                                        <?php echo $old_status_info['label']; ?>
                                    </span>
                                    <i class="fas fa-arrow-right" style="color: var(--gray); margin: 0 10px;"></i>
                                    <span style="color: <?php echo $new_status_info['color']; ?>; font-weight: bold;">
                                        <?php echo $new_status_info['label']; ?>
                                    </span>
                                </div>
                                <div class="history-date">
                                    <?php echo date('M d, Y h:i A', strtotime($history['changed_at'])); ?>
                                </div>
                            </div>
                            
                            <div class="history-user">
                                <div class="user-avatar-small">
                                    <?php echo strtoupper(substr($history['first_name'] ?? 'U', 0, 1) . substr($history['last_name'] ?? 'S', 0, 1)); ?>
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars(($history['first_name'] ?? 'Unknown') . ' ' . ($history['last_name'] ?? 'User')); ?></strong>
                                    <div style="color: var(--gray); font-size: 0.85rem;">
                                        <?php echo ucfirst($history['role'] ?? 'user'); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($history['notes']): ?>
                            <div class="history-notes">
                                <strong>Notes:</strong> <?php echo htmlspecialchars($history['notes']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($history['next_step']): ?>
                            <div style="margin-top: 10px; font-size: 0.9rem;">
                                <i class="fas fa-arrow-circle-right" style="color: var(--qc-green);"></i>
                                <strong>Next Step:</strong> <?php echo ucwords(str_replace('_', ' ', $history['next_step'])); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($history['target_date']): ?>
                            <div style="margin-top: 5px; font-size: 0.9rem;">
                                <i class="fas fa-calendar-alt" style="color: var(--qc-blue);"></i>
                                <strong>Target Date:</strong> <?php echo date('M d, Y', strtotime($history['target_date'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Status Updates Module</h3>
                    <p>
                        Track, monitor, and update the status of ordinances and resolutions. 
                        View status history, update document progression, and manage workflow transitions.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="tracking.php"><i class="fas fa-binoculars"></i> Tracking Dashboard</a></li>
                    <li><a href="timeline.php"><i class="fas fa-stream"></i> Timeline Tracking</a></li>
                    <li><a href="action_history.php"><i class="fas fa-history"></i> Action History</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Status Workflow Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Status Update Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Status Updates Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All status update activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Status Tracking Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // Bulk update functionality
        let selectedDocuments = [];
        
        function updateSelectedCount() {
            const count = selectedDocuments.length;
            document.getElementById('selectedCount').textContent = count + ' Selected';
            document.getElementById('bulkUpdateBtn').disabled = count === 0;
            
            // Update hidden field
            const container = document.getElementById('bulkDocumentIds');
            container.innerHTML = '';
            selectedDocuments.forEach(docInfo => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bulk_document_ids[]';
                input.value = docInfo;
                container.appendChild(input);
            });
        }
        
        function selectAllDocuments() {
            const checkboxes = document.querySelectorAll('.document-checkbox');
            selectedDocuments = [];
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
                const docInfo = checkbox.getAttribute('data-doc-info');
                if (docInfo && !selectedDocuments.includes(docInfo)) {
                    selectedDocuments.push(docInfo);
                }
            });
            
            document.getElementById('selectAllCheckbox').checked = true;
            updateSelectedCount();
        }
        
        function deselectAllDocuments() {
            const checkboxes = document.querySelectorAll('.document-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            
            selectedDocuments = [];
            document.getElementById('selectAllCheckbox').checked = false;
            updateSelectedCount();
        }
        
        // Document checkbox change handler
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('document-checkbox')) {
                const docInfo = e.target.getAttribute('data-doc-info');
                
                if (e.target.checked) {
                    if (!selectedDocuments.includes(docInfo)) {
                        selectedDocuments.push(docInfo);
                    }
                } else {
                    const index = selectedDocuments.indexOf(docInfo);
                    if (index > -1) {
                        selectedDocuments.splice(index, 1);
                    }
                }
                
                // Update select all checkbox
                const totalCheckboxes = document.querySelectorAll('.document-checkbox').length;
                const checkedCheckboxes = document.querySelectorAll('.document-checkbox:checked').length;
                document.getElementById('selectAllCheckbox').checked = checkedCheckboxes === totalCheckboxes;
                
                updateSelectedCount();
            }
            
            if (e.target.id === 'selectAllCheckbox') {
                const checkboxes = document.querySelectorAll('.document-checkbox');
                selectedDocuments = [];
                
                checkboxes.forEach(checkbox => {
                    checkbox.checked = e.target.checked;
                    if (e.target.checked) {
                        const docInfo = checkbox.getAttribute('data-doc-info');
                        if (docInfo && !selectedDocuments.includes(docInfo)) {
                            selectedDocuments.push(docInfo);
                        }
                    }
                });
                
                updateSelectedCount();
            }
        });
        
        // Status selection
        function selectStatus(status) {
            const options = document.querySelectorAll('.status-option:not(.disabled)');
            options.forEach(option => {
                option.classList.remove('selected');
                const radio = option.querySelector('.status-radio');
                if (radio && radio.value === status) {
                    option.classList.add('selected');
                    radio.checked = true;
                }
            });
        }
        
        // Update status button click
        document.querySelectorAll('.update-status-btn').forEach(button => {
            button.addEventListener('click', function() {
                const docId = this.getAttribute('data-doc-id');
                const docType = this.getAttribute('data-doc-type');
                const docNumber = this.getAttribute('data-doc-number');
                const currentStatus = this.getAttribute('data-current-status');
                
                // Scroll to form and update URL
                const url = new URL(window.location);
                url.searchParams.set('doc_id', docId);
                url.searchParams.set('doc_type', docType);
                window.history.pushState({}, '', url);
                
                // Reload page to show form
                window.location.href = `status_updates.php?doc_id=${docId}&doc_type=${docType}`;
            });
        });
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                // Validate status update form
                if (this.querySelector('input[name="update_status"]')) {
                    const statusSelected = this.querySelector('input[name="new_status"]:checked');
                    const notes = this.querySelector('textarea[name="status_notes"]');
                    
                    if (!statusSelected) {
                        e.preventDefault();
                        alert('Please select a new status for the document.');
                        return;
                    }
                    
                    if (!notes.value.trim()) {
                        e.preventDefault();
                        alert('Please provide notes explaining the status change.');
                        return;
                    }
                    
                    if (!confirm('Are you sure you want to update the status of this document?')) {
                        e.preventDefault();
                        return;
                    }
                }
                
                // Validate bulk update form
                if (this.querySelector('input[name="bulk_update"]')) {
                    if (selectedDocuments.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one document for bulk update.');
                        return;
                    }
                    
                    const bulkStatus = this.querySelector('select[name="bulk_status"]');
                    if (!bulkStatus.value) {
                        e.preventDefault();
                        alert('Please select a status for bulk update.');
                        return;
                    }
                    
                    if (!confirm(`Are you sure you want to update ${selectedDocuments.length} document(s) to "${bulkStatus.options[bulkStatus.selectedIndex].text}"?`)) {
                        e.preventDefault();
                        return;
                    }
                }
            });
        });
        
        // Refresh table
        function refreshTable() {
            window.location.reload();
        }
        
        // Auto-select status if only one option is available
        document.addEventListener('DOMContentLoaded', function() {
            const statusOptions = document.querySelectorAll('.status-option:not(.disabled):not(.current)');
            if (statusOptions.length === 1) {
                const radio = statusOptions[0].querySelector('.status-radio');
                if (radio && !radio.disabled) {
                    radio.checked = true;
                    statusOptions[0].classList.add('selected');
                }
            }
            
            // Highlight selected row
            const selectedRow = document.querySelector('.selected-row');
            if (selectedRow) {
                selectedRow.style.backgroundColor = 'rgba(0, 51, 102, 0.05)';
                selectedRow.style.borderLeft = '4px solid var(--qc-blue)';
            }
            
            // Add animation to table rows
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
            
            // Observe table rows
            document.querySelectorAll('.documents-table tbody tr').forEach(row => {
                observer.observe(row);
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            
            // Escape to clear selection
            if (e.key === 'Escape') {
                deselectAllDocuments();
            }
        });
    </script>
</body>
</html>