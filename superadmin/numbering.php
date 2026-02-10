<?php
// numbering.php - Reference Numbering Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to assign numbers
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
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

// Handle number assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_number'])) {
    try {
        $conn->beginTransaction();
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $reference_number = $_POST['reference_number'];
        $classification_id = $_POST['classification_id'];
        
        // Validate that the number is unique
        $check_query = "SELECT id FROM document_classification WHERE reference_number = :ref_num AND id != :class_id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':ref_num', $reference_number);
        $check_stmt->bindParam(':class_id', $classification_id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            throw new Exception("Reference number already exists!");
        }
        
        // Get old number for logging
        $old_number_query = "SELECT reference_number FROM document_classification WHERE id = :id";
        $old_stmt = $conn->prepare($old_number_query);
        $old_stmt->bindParam(':id', $classification_id);
        $old_stmt->execute();
        $old_number = $old_stmt->fetchColumn();
        
        // Update document classification with new reference number
        $update_query = "UPDATE document_classification SET reference_number = :ref_num, status = 'classified' WHERE id = :id";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':ref_num', $reference_number);
        $update_stmt->bindParam(':id', $classification_id);
        $update_stmt->execute();
        
        // Update main document table
        if ($document_type === 'ordinance') {
            $doc_update = "UPDATE ordinances SET ordinance_number = :ref_num WHERE id = :doc_id";
        } else {
            $doc_update = "UPDATE resolutions SET resolution_number = :ref_num WHERE id = :doc_id";
        }
        $doc_stmt = $conn->prepare($doc_update);
        $doc_stmt->bindParam(':ref_num', $reference_number);
        $doc_stmt->bindParam(':doc_id', $document_id);
        $doc_stmt->execute();
        
        // Log the numbering change
        $log_query = "INSERT INTO document_numbering_logs (document_id, document_type, old_number, new_number, reason, changed_by) 
                     VALUES (:doc_id, :doc_type, :old_num, :new_num, :reason, :user_id)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bindParam(':doc_id', $document_id);
        $log_stmt->bindParam(':doc_type', $document_type);
        $log_stmt->bindParam(':old_num', $old_number);
        $log_stmt->bindParam(':new_num', $reference_number);
        $log_stmt->bindParam(':reason', $_POST['reason']);
        $log_stmt->bindParam(':user_id', $user_id);
        $log_stmt->execute();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'NUMBERING_ASSIGN', 'Assigned reference number {$reference_number} to {$document_type} ID: {$document_id}', :ip, :agent)";
        $audit_stmt = $conn->prepare($audit_query);
        $audit_stmt->bindParam(':user_id', $user_id);
        $audit_stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $audit_stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $audit_stmt->execute();
        
        $conn->commit();
        $success_message = "Reference number assigned successfully!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = "Error assigning number: " . $e->getMessage();
    }
}

// Handle bulk numbering
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_assign'])) {
    try {
        $conn->beginTransaction();
        
        $documents = $_POST['bulk_documents'] ?? [];
        $numbering_scheme = $_POST['bulk_scheme'];
        
        foreach ($documents as $doc_data) {
            list($doc_id, $doc_type, $class_id) = explode('|', $doc_data);
            
            // Generate number based on scheme
            $reference_number = generateReferenceNumber($conn, $doc_type, $numbering_scheme);
            
            // Assign the number
            assignReferenceNumber($conn, $doc_id, $doc_type, $class_id, $reference_number, $user_id, 'Bulk numbering assignment');
        }
        
        $conn->commit();
        $success_message = "Bulk numbering completed successfully!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = "Error in bulk numbering: " . $e->getMessage();
    }
}

// Handle numbering configuration update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_config'])) {
    try {
        $prefix = $_POST['prefix'];
        $doc_type = $_POST['config_doc_type'];
        $year = $_POST['year'];
        $sequence = $_POST['sequence'];
        $format_pattern = $_POST['format_pattern'];
        $description = $_POST['description'];
        
        // Check if configuration exists
        $check_query = "SELECT id FROM reference_numbering WHERE prefix = :prefix AND document_type = :doc_type AND year = :year";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':prefix', $prefix);
        $check_stmt->bindParam(':doc_type', $doc_type);
        $check_stmt->bindParam(':year', $year);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing
            $update_query = "UPDATE reference_numbering SET sequence = :seq, format_pattern = :pattern, description = :desc, updated_at = NOW() 
                            WHERE prefix = :prefix AND document_type = :doc_type AND year = :year";
            $stmt = $conn->prepare($update_query);
            $stmt->bindParam(':seq', $sequence);
            $stmt->bindParam(':pattern', $format_pattern);
            $stmt->bindParam(':desc', $description);
            $stmt->bindParam(':prefix', $prefix);
            $stmt->bindParam(':doc_type', $doc_type);
            $stmt->bindParam(':year', $year);
            $stmt->execute();
        } else {
            // Insert new
            $insert_query = "INSERT INTO reference_numbering (prefix, document_type, year, sequence, format_pattern, description, created_by) 
                           VALUES (:prefix, :doc_type, :year, :seq, :pattern, :desc, :user_id)";
            $stmt = $conn->prepare($insert_query);
            $stmt->bindParam(':prefix', $prefix);
            $stmt->bindParam(':doc_type', $doc_type);
            $stmt->bindParam(':year', $year);
            $stmt->bindParam(':seq', $sequence);
            $stmt->bindParam(':pattern', $format_pattern);
            $stmt->bindParam(':desc', $description);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
        }
        
        $success_message = "Numbering configuration updated successfully!";
        
    } catch (Exception $e) {
        $error_message = "Error updating configuration: " . $e->getMessage();
    }
}

// Function to generate reference number
function generateReferenceNumber($conn, $doc_type, $scheme = 'auto') {
    $year = date('Y');
    
    if ($scheme === 'auto') {
        // Auto-generate based on existing patterns
        $prefix = ($doc_type === 'ordinance') ? 'QC-ORD' : 'QC-RES';
        $query = "SELECT sequence FROM reference_numbering WHERE prefix = :prefix AND document_type = :doc_type AND year = :year";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':prefix', $prefix);
        $stmt->bindParam(':doc_type', $doc_type);
        $stmt->bindParam(':year', $year);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch();
            $sequence = $result['sequence'];
            
            // Increment sequence for next use
            $update_query = "UPDATE reference_numbering SET sequence = sequence + 1, last_used_date = CURDATE() 
                           WHERE prefix = :prefix AND document_type = :doc_type AND year = :year";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':prefix', $prefix);
            $update_stmt->bindParam(':doc_type', $doc_type);
            $update_stmt->bindParam(':year', $year);
            $update_stmt->execute();
            
            return sprintf('%s-%d-%04d', $prefix, $year, $sequence);
        }
    }
    
    // Default fallback
    $month = date('m');
    $day = date('d');
    $random = rand(1000, 9999);
    
    if ($doc_type === 'ordinance') {
        return sprintf('QC-ORD-%d-%02d-%02d-%04d', $year, $month, $day, $random);
    } else {
        return sprintf('QC-RES-%d-%02d-%02d-%04d', $year, $month, $day, $random);
    }
}

// Function to assign reference number
function assignReferenceNumber($conn, $doc_id, $doc_type, $class_id, $ref_num, $user_id, $reason) {
    // Get old number
    $old_query = "SELECT reference_number FROM document_classification WHERE id = :id";
    $old_stmt = $conn->prepare($old_query);
    $old_stmt->bindParam(':id', $class_id);
    $old_stmt->execute();
    $old_number = $old_stmt->fetchColumn();
    
    // Update classification
    $update_query = "UPDATE document_classification SET reference_number = :ref_num, status = 'classified' WHERE id = :id";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':ref_num', $ref_num);
    $update_stmt->bindParam(':id', $class_id);
    $update_stmt->execute();
    
    // Update main document
    if ($doc_type === 'ordinance') {
        $doc_update = "UPDATE ordinances SET ordinance_number = :ref_num WHERE id = :doc_id";
    } else {
        $doc_update = "UPDATE resolutions SET resolution_number = :ref_num WHERE id = :doc_id";
    }
    $doc_stmt = $conn->prepare($doc_update);
    $doc_stmt->bindParam(':ref_num', $ref_num);
    $doc_stmt->bindParam(':doc_id', $doc_id);
    $doc_stmt->execute();
    
    // Log the change
    $log_query = "INSERT INTO document_numbering_logs (document_id, document_type, old_number, new_number, reason, changed_by) 
                 VALUES (:doc_id, :doc_type, :old_num, :new_num, :reason, :user_id)";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bindParam(':doc_id', $doc_id);
    $log_stmt->bindParam(':doc_type', $doc_type);
    $log_stmt->bindParam(':old_num', $old_number);
    $log_stmt->bindParam(':new_num', $ref_num);
    $log_stmt->bindParam(':reason', $reason);
    $log_stmt->bindParam(':user_id', $user_id);
    $log_stmt->execute();
    
    return true;
}

// Get documents pending numbering
$pending_query = "
    SELECT 
        dc.id as classification_id,
        dc.document_id,
        dc.document_type,
        dc.classification_type,
        dc.priority_level,
        dc.status,
        dc.classified_at,
        c.category_name,
        u.first_name,
        u.last_name,
        CASE 
            WHEN dc.document_type = 'ordinance' THEN o.title
            WHEN dc.document_type = 'resolution' THEN r.title
        END as title,
        CASE 
            WHEN dc.document_type = 'ordinance' THEN o.ordinance_number
            WHEN dc.document_type = 'resolution' THEN r.resolution_number
        END as current_number
    FROM document_classification dc
    LEFT JOIN document_categories c ON dc.category_id = c.id
    LEFT JOIN users u ON dc.classified_by = u.id
    LEFT JOIN ordinances o ON dc.document_type = 'ordinance' AND dc.document_id = o.id
    LEFT JOIN resolutions r ON dc.document_type = 'resolution' AND dc.document_id = r.id
    WHERE (dc.reference_number IS NULL OR dc.reference_number = '') 
    AND dc.status IN ('classified', 'reviewed')
    ORDER BY dc.priority_level DESC, dc.classified_at ASC
    LIMIT 50";

$pending_stmt = $conn->query($pending_query);
$pending_documents = $pending_stmt->fetchAll();

// Get recently numbered documents
$recent_query = "
    SELECT 
        dc.reference_number,
        dc.document_type,
        dc.classification_type,
        c.category_name,
        dc.priority_level,
        u.first_name,
        u.last_name,
        dc.classified_at,
        CASE 
            WHEN dc.document_type = 'ordinance' THEN o.title
            WHEN dc.document_type = 'resolution' THEN r.title
        END as title
    FROM document_classification dc
    LEFT JOIN document_categories c ON dc.category_id = c.id
    LEFT JOIN users u ON dc.classified_by = u.id
    LEFT JOIN ordinances o ON dc.document_type = 'ordinance' AND dc.document_id = o.id
    LEFT JOIN resolutions r ON dc.document_type = 'resolution' AND dc.document_id = r.id
    WHERE dc.reference_number IS NOT NULL 
    AND dc.reference_number != ''
    ORDER BY dc.classified_at DESC
    LIMIT 20";

$recent_stmt = $conn->query($recent_query);
$recent_documents = $recent_stmt->fetchAll();

// Get numbering configurations
$config_query = "SELECT * FROM reference_numbering WHERE is_active = 1 ORDER BY document_type, prefix, year";
$config_stmt = $conn->query($config_query);
$numbering_configs = $config_stmt->fetchAll();

// Get numbering statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_documents,
        SUM(CASE WHEN reference_number IS NOT NULL THEN 1 ELSE 0 END) as numbered,
        SUM(CASE WHEN reference_number IS NULL THEN 1 ELSE 0 END) as pending,
        document_type,
        EXTRACT(YEAR FROM classified_at) as year
    FROM document_classification 
    WHERE status IN ('classified', 'reviewed', 'approved')
    GROUP BY document_type, EXTRACT(YEAR FROM classified_at)
    ORDER BY year DESC, document_type";

$stats_stmt = $conn->query($stats_query);
$numbering_stats = $stats_stmt->fetchAll();

// Get numbering logs
$logs_query = "
    SELECT 
        nl.*,
        u.first_name,
        u.last_name,
        CASE 
            WHEN nl.document_type = 'ordinance' THEN o.title
            WHEN nl.document_type = 'resolution' THEN r.title
        END as title
    FROM document_numbering_logs nl
    LEFT JOIN users u ON nl.changed_by = u.id
    LEFT JOIN ordinances o ON nl.document_type = 'ordinance' AND nl.document_id = o.id
    LEFT JOIN resolutions r ON nl.document_type = 'resolution' AND nl.document_id = r.id
    ORDER BY nl.changed_at DESC
    LIMIT 50";

$logs_stmt = $conn->query($logs_query);
$numbering_logs = $logs_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reference Numbering | QC Ordinance Tracker</title>
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
            --orange: #ED8936;
            --purple: #9F7AEA;
            --teal: #38B2AC;
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
        
        /* SIDEBAR STYLES - Using your existing sidebar structure */
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
        
        /* MODULE HEADER */
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: var(--border-radius);
            padding: 20px;
            backdrop-filter: blur(10px);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--white);
            line-height: 1;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        /* Alerts */
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
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .dashboard-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-light);
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: var(--qc-blue);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .card-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        /* Document List */
        .document-list {
            list-style: none;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .document-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .document-item:hover {
            background: var(--off-white);
        }
        
        .document-item:last-child {
            border-bottom: none;
        }
        
        .document-type {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: bold;
            font-size: 0.8rem;
        }
        
        .document-type.ordinance {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
        }
        
        .document-type.resolution {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
        }
        
        .document-content {
            flex: 1;
            min-width: 0;
        }
        
        .document-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .document-meta {
            display: flex;
            gap: 10px;
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .document-priority {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-low { background: #f7fafc; color: #4a5568; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-high { background: #feb2b2; color: #9b2c2c; }
        .priority-urgent { background: #fed7d7; color: #c53030; }
        .priority-emergency { background: #f56565; color: white; }
        
        .document-actions {
            display: flex;
            gap: 10px;
        }
        
        /* Forms */
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
        
        .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            background: var(--white);
            cursor: pointer;
        }
        
        .form-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            min-height: 100px;
            resize: vertical;
            font-family: 'Times New Roman', Times, serif;
        }
        
        /* Buttons */
        .btn {
            padding: 12px 24px;
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
            padding: 8px 16px;
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
        
        .btn-success {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
            color: var(--white);
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--orange) 0%, #dd6b20 100%);
            color: var(--white);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        .btn-secondary {
            background: var(--gray-light);
            color: var(--gray-dark);
        }
        
        .btn-secondary:hover {
            background: var(--gray);
            color: var(--white);
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            background: var(--white);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: var(--qc-blue);
            color: var(--white);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--qc-gold);
        }
        
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .data-table tr:hover {
            background: var(--off-white);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .badge-primary {
            background: rgba(0, 51, 102, 0.1);
            color: var(--qc-blue);
        }
        
        .badge-success {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
        }
        
        .badge-warning {
            background: rgba(237, 137, 54, 0.1);
            color: var(--orange);
        }
        
        .badge-danger {
            background: rgba(197, 48, 48, 0.1);
            color: var(--red);
        }
        
        /* Modal */
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
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 90%;
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
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        /* Numbering Patterns */
        .numbering-patterns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .pattern-card {
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .pattern-card:hover {
            border-color: var(--qc-blue);
            box-shadow: var(--shadow-sm);
        }
        
        .pattern-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .pattern-prefix {
            font-weight: bold;
            color: var(--qc-blue);
            font-size: 1.1rem;
        }
        
        .pattern-type {
            padding: 2px 8px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .pattern-format {
            font-family: monospace;
            background: var(--white);
            padding: 10px;
            border-radius: 4px;
            border: 1px solid var(--gray-light);
            margin: 10px 0;
            font-size: 0.9rem;
        }
        
        .pattern-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid var(--gray-light);
            margin-bottom: 30px;
        }
        
        .tab {
            padding: 15px 30px;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab:hover {
            color: var(--qc-blue);
        }
        
        .tab.active {
            color: var(--qc-blue);
            border-bottom-color: var(--qc-blue);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* FOOTER */
        .government-footer {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 40px 0 20px;
            margin-top: 60px;
            margin-left: var(--sidebar-width);
        }
        
        .footer-content {
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
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            max-width: 1400px;
            margin: 0 auto;
            padding-left: 20px;
            padding-right: 20px;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
            }
            
            .government-footer {
                margin-left: 0;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
        }
        
        @media (max-width: 768px) {
            .module-stats {
                grid-template-columns: 1fr;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .numbering-patterns {
                grid-template-columns: 1fr;
            }
            
            .module-header {
                padding: 30px 20px;
            }
            
            .module-title h1 {
                font-size: 2rem;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .checkbox-input {
            width: 18px;
            height: 18px;
            accent-color: var(--qc-blue);
        }
        
        /* Progress Bar */
        .progress-bar {
            height: 10px;
            background: var(--gray-light);
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--qc-green);
            border-radius: 5px;
            transition: width 0.3s ease;
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
                    <p>Module 2: Reference Numbering | Classification & Organization</p>
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
                        <i class="fas fa-hashtag"></i> Reference Numbering
                    </h3>
                    <p class="sidebar-subtitle">Module 2: Classification & Organization</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="numbering-module">
                <!-- MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">REFERENCE NUMBERING MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-hashtag"></i>
                            </div>
                            <div class="module-title">
                                <h1>Official Reference Numbering System</h1>
                                <p class="module-subtitle">
                                    Assign official reference numbers to ordinances and resolutions. 
                                    Track numbering patterns, manage sequences, and maintain numbering integrity.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo count($pending_documents); ?></div>
                                <div class="stat-label">Documents Pending Numbering</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo count($recent_documents); ?></div>
                                <div class="stat-label">Recently Numbered</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo count($numbering_configs); ?></div>
                                <div class="stat-label">Numbering Patterns</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo count($numbering_logs); ?></div>
                                <div class="stat-label">Numbering Actions Logged</div>
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

                <!-- Tabs Navigation -->
                <div class="tabs">
                    <button class="tab active" data-tab="pending">Pending Numbering</button>
                    <button class="tab" data-tab="patterns">Numbering Patterns</button>
                    <button class="tab" data-tab="recent">Recently Numbered</button>
                    <button class="tab" data-tab="logs">Numbering Logs</button>
                    <button class="tab" data-tab="bulk">Bulk Numbering</button>
                </div>

                <!-- PENDING NUMBERING TAB -->
                <div class="tab-content active" id="pending-tab">
                    <div class="dashboard-grid">
                        <!-- Pending Documents List -->
                        <div class="dashboard-card">
                            <div class="card-header">
                                <div class="card-icon" style="background: var(--orange);">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <h2 class="card-title">Documents Awaiting Numbering</h2>
                            </div>
                            
                            <?php if (empty($pending_documents)): ?>
                            <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                                <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--qc-green); margin-bottom: 15px;"></i>
                                <h3 style="color: var(--qc-green); margin-bottom: 10px;">All Documents Numbered</h3>
                                <p>No documents are currently awaiting reference numbering.</p>
                            </div>
                            <?php else: ?>
                            <ul class="document-list">
                                <?php foreach ($pending_documents as $doc): 
                                    $doc_type_class = $doc['document_type'];
                                    $doc_type_label = $doc['document_type'] === 'ordinance' ? 'ORD' : 'RES';
                                    $priority_class = strtolower(str_replace(' ', '-', $doc['priority_level']));
                                ?>
                                <li class="document-item">
                                    <div class="document-type <?php echo $doc_type_class; ?>">
                                        <?php echo $doc_type_label; ?>
                                    </div>
                                    <div class="document-content">
                                        <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                        <div class="document-meta">
                                            <span><?php echo htmlspecialchars($doc['classification_type']); ?></span>
                                            <span class="document-priority priority-<?php echo $priority_class; ?>">
                                                <?php echo ucfirst($doc['priority_level']); ?>
                                            </span>
                                            <span><?php echo htmlspecialchars($doc['category_name'] ?? 'Uncategorized'); ?></span>
                                        </div>
                                    </div>
                                    <div class="document-actions">
                                        <button class="btn btn-primary btn-sm assign-number-btn" 
                                                data-doc-id="<?php echo $doc['document_id']; ?>"
                                                data-doc-type="<?php echo $doc['document_type']; ?>"
                                                data-class-id="<?php echo $doc['classification_id']; ?>"
                                                data-title="<?php echo htmlspecialchars($doc['title']); ?>">
                                            <i class="fas fa-hashtag"></i> Assign Number
                                        </button>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Statistics -->
                        <div class="dashboard-card">
                            <div class="card-header">
                                <div class="card-icon" style="background: var(--teal);">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <h2 class="card-title">Numbering Statistics</h2>
                            </div>
                            
                            <?php 
                            $total_docs = 0;
                            $numbered_docs = 0;
                            $pending_docs = 0;
                            $by_year = [];
                            
                            foreach ($numbering_stats as $stat) {
                                $total_docs += $stat['total_documents'];
                                $numbered_docs += $stat['numbered'];
                                $pending_docs += $stat['pending'];
                                $by_year[$stat['year']][$stat['document_type']] = $stat;
                            }
                            
                            $progress = $total_docs > 0 ? round(($numbered_docs / $total_docs) * 100) : 0;
                            ?>
                            
                            <div style="margin-bottom: 20px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <span>Overall Progress</span>
                                    <span><?php echo $progress; ?>%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                                </div>
                            </div>
                            
                            <div style="display: grid; gap: 15px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Total Documents:</span>
                                    <strong><?php echo $total_docs; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Numbered Documents:</span>
                                    <strong style="color: var(--qc-green);"><?php echo $numbered_docs; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Pending Numbering:</span>
                                    <strong style="color: var(--orange);"><?php echo $pending_docs; ?></strong>
                                </div>
                            </div>
                            
                            <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--gray-light);">
                                <h3 style="font-size: 1rem; color: var(--qc-blue); margin-bottom: 15px;">By Year & Type</h3>
                                <?php foreach ($by_year as $year => $types): ?>
                                <div style="margin-bottom: 15px;">
                                    <div style="font-weight: 600; color: var(--gray-dark); margin-bottom: 5px;"><?php echo $year; ?></div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.9rem;">
                                        <?php foreach (['ordinance', 'resolution'] as $type): 
                                            $type_data = $types[$type] ?? ['total_documents' => 0, 'numbered' => 0];
                                            $type_label = $type === 'ordinance' ? 'Ordinances' : 'Resolutions';
                                        ?>
                                        <div>
                                            <div><?php echo $type_label; ?>:</div>
                                            <div><?php echo $type_data['numbered']; ?> / <?php echo $type_data['total_documents']; ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NUMBERING PATTERNS TAB -->
                <div class="tab-content" id="patterns-tab">
                    <div class="dashboard-card fade-in">
                        <div class="card-header">
                            <div class="card-icon" style="background: var(--purple);">
                                <i class="fas fa-code"></i>
                            </div>
                            <h2 class="card-title">Numbering Patterns & Configurations</h2>
                            <button class="btn btn-primary" id="addPatternBtn" style="margin-left: auto;">
                                <i class="fas fa-plus"></i> Add Pattern
                            </button>
                        </div>
                        
                        <div class="numbering-patterns">
                            <?php foreach ($numbering_configs as $config): 
                                $type_color = $config['document_type'] === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                            ?>
                            <div class="pattern-card">
                                <div class="pattern-header">
                                    <div class="pattern-prefix"><?php echo htmlspecialchars($config['prefix']); ?></div>
                                    <div class="pattern-type" style="background: <?php echo $type_color; ?>; color: white;">
                                        <?php echo ucfirst($config['document_type']); ?>
                                    </div>
                                </div>
                                
                                <div class="pattern-format">
                                    <?php echo htmlspecialchars($config['format_pattern']); ?>
                                </div>
                                
                                <div class="pattern-stats">
                                    <div>
                                        <i class="fas fa-calendar"></i>
                                        Year: <?php echo $config['year']; ?>
                                    </div>
                                    <div>
                                        <i class="fas fa-sort-numeric-down"></i>
                                        Sequence: <?php echo $config['sequence']; ?>
                                    </div>
                                </div>
                                
                                <?php if ($config['description']): ?>
                                <div style="margin-top: 10px; font-size: 0.9rem; color: var(--gray);">
                                    <?php echo htmlspecialchars($config['description']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div style="margin-top: 15px; display: flex; gap: 10px;">
                                    <button class="btn btn-sm btn-warning edit-pattern-btn" 
                                            data-prefix="<?php echo htmlspecialchars($config['prefix']); ?>"
                                            data-doc-type="<?php echo $config['document_type']; ?>"
                                            data-year="<?php echo $config['year']; ?>"
                                            data-sequence="<?php echo $config['sequence']; ?>"
                                            data-pattern="<?php echo htmlspecialchars($config['format_pattern']); ?>"
                                            data-desc="<?php echo htmlspecialchars($config['description'] ?? ''); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-sm btn-danger delete-pattern-btn"
                                            data-prefix="<?php echo htmlspecialchars($config['prefix']); ?>"
                                            data-year="<?php echo $config['year']; ?>">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (empty($numbering_configs)): ?>
                        <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                            <i class="fas fa-cogs" style="font-size: 3rem; color: var(--gray-light); margin-bottom: 15px;"></i>
                            <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Numbering Patterns</h3>
                            <p>Click "Add Pattern" to create your first numbering pattern.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RECENTLY NUMBERED TAB -->
                <div class="tab-content" id="recent-tab">
                    <div class="dashboard-card fade-in">
                        <div class="card-header">
                            <div class="card-icon" style="background: var(--qc-green);">
                                <i class="fas fa-history"></i>
                            </div>
                            <h2 class="card-title">Recently Numbered Documents</h2>
                        </div>
                        
                        <?php if (empty($recent_documents)): ?>
                        <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                            <i class="fas fa-inbox" style="font-size: 3rem; color: var(--gray-light); margin-bottom: 15px;"></i>
                            <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Recent Numbering</h3>
                            <p>No documents have been assigned reference numbers recently.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Reference Number</th>
                                        <th>Document Type</th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Priority</th>
                                        <th>Numbered By</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_documents as $doc): 
                                        $priority_class = strtolower(str_replace(' ', '-', $doc['priority_level']));
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--qc-blue);"><?php echo htmlspecialchars($doc['reference_number']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $doc['document_type'] === 'ordinance' ? 'badge-primary' : 'badge-success'; ?>">
                                                <?php echo ucfirst($doc['document_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td>
                                            <span class="document-priority priority-<?php echo $priority_class; ?>">
                                                <?php echo ucfirst($doc['priority_level']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($doc['classified_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- NUMBERING LOGS TAB -->
                <div class="tab-content" id="logs-tab">
                    <div class="dashboard-card fade-in">
                        <div class="card-header">
                            <div class="card-icon" style="background: var(--gray-dark);">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h2 class="card-title">Numbering Activity Logs</h2>
                        </div>
                        
                        <?php if (empty($numbering_logs)): ?>
                        <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                            <i class="fas fa-scroll" style="font-size: 3rem; color: var(--gray-light); margin-bottom: 15px;"></i>
                            <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Activity Logs</h3>
                            <p>No numbering activities have been logged yet.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Document</th>
                                        <th>Type</th>
                                        <th>Old Number</th>
                                        <th>New Number</th>
                                        <th>Reason</th>
                                        <th>Changed By</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($numbering_logs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['title']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $log['document_type'] === 'ordinance' ? 'badge-primary' : 'badge-success'; ?>">
                                                <?php echo ucfirst($log['document_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['old_number']): ?>
                                            <span style="color: var(--gray);"><?php echo htmlspecialchars($log['old_number']); ?></span>
                                            <?php else: ?>
                                            <span style="color: var(--gray); font-style: italic;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: var(--qc-blue);"><?php echo htmlspecialchars($log['new_number']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['reason']); ?></td>
                                        <td><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($log['changed_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- BULK NUMBERING TAB -->
                <div class="tab-content" id="bulk-tab">
                    <div class="dashboard-grid">
                        <!-- Bulk Numbering Form -->
                        <div class="dashboard-card">
                            <div class="card-header">
                                <div class="card-icon" style="background: var(--orange);">
                                    <i class="fas fa-bulk"></i>
                                </div>
                                <h2 class="card-title">Bulk Numbering Assignment</h2>
                            </div>
                            
                            <form id="bulkNumberingForm" method="POST">
                                <input type="hidden" name="bulk_assign" value="1">
                                
                                <div class="form-group">
                                    <label class="form-label required">Select Numbering Scheme</label>
                                    <select name="bulk_scheme" class="form-select" required>
                                        <option value="">-- Select Scheme --</option>
                                        <option value="auto">Auto-generate (Default Pattern)</option>
                                        <option value="custom">Custom Pattern</option>
                                        <option value="sequential">Sequential by Date</option>
                                        <option value="yearly">Year-based Sequence</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label required">Select Documents for Bulk Numbering</label>
                                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--gray-light); border-radius: var(--border-radius); padding: 15px;">
                                        <?php foreach ($pending_documents as $doc): ?>
                                        <div class="checkbox-group">
                                            <input type="checkbox" 
                                                   name="bulk_documents[]" 
                                                   value="<?php echo $doc['document_id'] . '|' . $doc['document_type'] . '|' . $doc['classification_id']; ?>"
                                                   class="checkbox-input">
                                            <span>
                                                <strong><?php echo htmlspecialchars($doc['title']); ?></strong>
                                                <span style="color: var(--gray); font-size: 0.9rem;">
                                                    (<?php echo ucfirst($doc['document_type']); ?> - <?php echo ucfirst($doc['priority_level']); ?>)
                                                </span>
                                            </span>
                                        </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($pending_documents)): ?>
                                        <div style="text-align: center; padding: 20px; color: var(--gray);">
                                            <i class="fas fa-inbox"></i>
                                            <p>No documents available for bulk numbering.</p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Bulk Operation Notes</label>
                                    <textarea name="bulk_notes" class="form-textarea" placeholder="Add notes about this bulk numbering operation..."></textarea>
                                </div>
                                
                                <div style="display: flex; gap: 15px; margin-top: 30px;">
                                    <button type="submit" class="btn btn-success" <?php echo empty($pending_documents) ? 'disabled' : ''; ?>>
                                        <i class="fas fa-hashtag"></i> Execute Bulk Numbering
                                    </button>
                                    <button type="button" class="btn btn-secondary" id="selectAllBtn">
                                        <i class="fas fa-check-square"></i> Select All
                                    </button>
                                    <button type="button" class="btn btn-secondary" id="deselectAllBtn">
                                        <i class="fas fa-square"></i> Deselect All
                                    </button>
                                </div>
                                
                                <div style="margin-top: 20px; padding: 15px; background: var(--off-white); border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                                    <h4 style="color: var(--qc-blue); margin-bottom: 10px;">
                                        <i class="fas fa-info-circle"></i> Bulk Numbering Guidelines
                                    </h4>
                                    <ul style="font-size: 0.9rem; color: var(--gray); padding-left: 20px;">
                                        <li>Select multiple documents to assign numbers in bulk</li>
                                        <li>Numbers will be generated according to the selected scheme</li>
                                        <li>Each document will receive a unique reference number</li>
                                        <li>All actions are logged for audit purposes</li>
                                        <li>Bulk operations cannot be undone</li>
                                    </ul>
                                </div>
                            </form>
                        </div>

                        <!-- Bulk Preview -->
                        <div class="dashboard-card">
                            <div class="card-header">
                                <div class="card-icon" style="background: var(--teal);">
                                    <i class="fas fa-eye"></i>
                                </div>
                                <h2 class="card-title">Bulk Operation Preview</h2>
                            </div>
                            
                            <div id="bulkPreview" style="padding: 20px; text-align: center; color: var(--gray);">
                                <i class="fas fa-mouse-pointer" style="font-size: 3rem; color: var(--gray-light); margin-bottom: 15px;"></i>
                                <p>Select documents and a numbering scheme to see preview.</p>
                            </div>
                            
                            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--gray-light);">
                                <h3 style="font-size: 1rem; color: var(--qc-blue); margin-bottom: 15px;">Recent Bulk Operations</h3>
                                
                                <?php 
                                // Get recent bulk operations from logs
                                $bulk_logs_query = "
                                    SELECT * FROM document_numbering_logs 
                                    WHERE reason LIKE '%Bulk%' 
                                    ORDER BY changed_at DESC 
                                    LIMIT 5";
                                $bulk_logs_stmt = $conn->query($bulk_logs_query);
                                $bulk_logs = $bulk_logs_stmt->fetchAll();
                                ?>
                                
                                <?php if (empty($bulk_logs)): ?>
                                <div style="text-align: center; padding: 20px; color: var(--gray);">
                                    <p>No recent bulk operations found.</p>
                                </div>
                                <?php else: ?>
                                <ul style="list-style: none;">
                                    <?php foreach ($bulk_logs as $log): ?>
                                    <li style="padding: 10px 0; border-bottom: 1px solid var(--gray-light);">
                                        <div style="font-weight: 600; color: var(--gray-dark);">
                                            <?php echo date('M d, Y H:i', strtotime($log['changed_at'])); ?>
                                        </div>
                                        <div style="font-size: 0.9rem; color: var(--gray);">
                                            <?php echo htmlspecialchars($log['reason']); ?>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ASSIGN NUMBER MODAL -->
    <div class="modal-overlay" id="assignNumberModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-hashtag"></i> Assign Reference Number</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <form id="assignNumberForm" method="POST">
                    <input type="hidden" name="assign_number" value="1">
                    <input type="hidden" id="modalDocumentId" name="document_id">
                    <input type="hidden" id="modalDocumentType" name="document_type">
                    <input type="hidden" id="modalClassificationId" name="classification_id">
                    
                    <div class="form-group">
                        <label class="form-label">Document Information</label>
                        <div id="modalDocumentInfo" style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius);">
                            <!-- Document info will be loaded here -->
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Reference Number</label>
                        <input type="text" id="modalReferenceNumber" name="reference_number" class="form-control" required 
                               placeholder="e.g., QC-ORD-2026-0001">
                        <small style="color: var(--gray); display: block; margin-top: 5px;">
                            Format: QC-[TYPE]-[YEAR]-[SEQUENCE]
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Numbering Options</label>
                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button type="button" class="btn btn-secondary btn-sm" id="generateNumberBtn">
                                <i class="fas fa-magic"></i> Auto-generate
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" id="suggestNumberBtn">
                                <i class="fas fa-lightbulb"></i> Suggest
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" id="validateNumberBtn">
                                <i class="fas fa-check"></i> Validate
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Reason for Numbering</label>
                        <textarea id="modalReason" name="reason" class="form-textarea" required 
                                  placeholder="Explain why this reference number is being assigned..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Preview</label>
                        <div id="numberPreview" style="background: var(--white); padding: 15px; border: 2px solid var(--gray-light); border-radius: var(--border-radius);">
                            <p style="color: var(--gray); text-align: center;">Enter a reference number to see preview</p>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="modalCancelBtn">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Assign Number
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- PATTERN CONFIG MODAL -->
    <div class="modal-overlay" id="patternConfigModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-cogs"></i> Numbering Pattern Configuration</h2>
                <button class="modal-close" id="patternModalClose">&times;</button>
            </div>
            <div class="modal-body">
                <form id="patternConfigForm" method="POST">
                    <input type="hidden" name="update_config" value="1">
                    
                    <div class="form-group">
                        <label class="form-label required">Prefix</label>
                        <input type="text" id="configPrefix" name="prefix" class="form-control" required 
                               placeholder="e.g., QC-ORD, QC-RES, QC-REG">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Document Type</label>
                        <select id="configDocType" name="config_doc_type" class="form-select" required>
                            <option value="ordinance">Ordinance</option>
                            <option value="resolution">Resolution</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Year</label>
                        <input type="number" id="configYear" name="year" class="form-control" required 
                               min="2000" max="2100" value="<?php echo date('Y'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Starting Sequence</label>
                        <input type="number" id="configSequence" name="sequence" class="form-control" required 
                               min="1" value="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Format Pattern</label>
                        <input type="text" id="configPattern" name="format_pattern" class="form-control" required 
                               placeholder="e.g., QC-ORD-YYYY-SSSS">
                        <small style="color: var(--gray); display: block; margin-top: 5px;">
                            Variables: QC (City Code), TYPE (Document Type), YYYY (Year), MM (Month), DD (Day), SSSS (Sequence)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea id="configDescription" name="description" class="form-textarea" 
                                  placeholder="Describe this numbering pattern..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Pattern Preview</label>
                        <div id="patternPreview" style="background: var(--white); padding: 15px; border: 2px solid var(--qc-blue); border-radius: var(--border-radius); font-family: monospace; font-size: 1.2rem;">
                            <!-- Preview will be generated here -->
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="patternCancelBtn">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Pattern
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Reference Numbering Module</h3>
                    <p>
                        Official reference numbering system for Quezon City ordinances and resolutions. 
                        Manage numbering patterns, assign unique identifiers, and maintain numbering integrity.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Module 2 Tools</h3>
                <ul class="footer-links">
                    <li><a href="classification.php"><i class="fas fa-sitemap"></i> Classification Dashboard</a></li>
                    <li><a href="type_identification.php"><i class="fas fa-fingerprint"></i> Type Identification</a></li>
                    <li><a href="categorization.php"><i class="fas fa-folder"></i> Subject Categorization</a></li>
                    <li><a href="priority.php"><i class="fas fa-flag"></i> Priority Setting</a></li>
                    <li><a href="tagging.php"><i class="fas fa-tag"></i> Keyword Tagging</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Numbering Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Numbering Guidelines</a></li>
                    <li><a href="#"><i class="fas fa-file-contract"></i> Pattern Templates</a></li>
                    <li><a href="#"><i class="fas fa-history"></i> Numbering History</a></li>
                    <li><a href="#"><i class="fas fa-download"></i> Export Numbering Data</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Module 2: Reference Numbering System.</p>
            <p style="margin-top: 10px;">All numbering activities are logged and audited for compliance with Quezon City standards.</p>
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
        
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab');
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
        
        // Mobile menu functionality
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
        
        // Assign Number Modal
        const assignNumberModal = document.getElementById('assignNumberModal');
        const assignNumberBtns = document.querySelectorAll('.assign-number-btn');
        
        assignNumberBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const docId = this.getAttribute('data-doc-id');
                const docType = this.getAttribute('data-doc-type');
                const classId = this.getAttribute('data-class-id');
                const title = this.getAttribute('data-title');
                
                // Set form values
                document.getElementById('modalDocumentId').value = docId;
                document.getElementById('modalDocumentType').value = docType;
                document.getElementById('modalClassificationId').value = classId;
                
                // Update document info
                document.getElementById('modalDocumentInfo').innerHTML = `
                    <div style="display: grid; gap: 5px;">
                        <div><strong>Title:</strong> ${title}</div>
                        <div><strong>Type:</strong> ${docType === 'ordinance' ? 'Ordinance' : 'Resolution'}</div>
                        <div><strong>Document ID:</strong> ${docId}</div>
                    </div>
                `;
                
                // Generate initial number
                generateNumber(docType);
                
                // Open modal
                assignNumberModal.classList.add('active');
            });
        });
        
        // Close modals
        document.getElementById('modalClose').addEventListener('click', () => {
            assignNumberModal.classList.remove('active');
        });
        
        document.getElementById('patternModalClose').addEventListener('click', () => {
            patternConfigModal.classList.remove('active');
        });
        
        document.getElementById('modalCancelBtn').addEventListener('click', () => {
            assignNumberModal.classList.remove('active');
        });
        
        document.getElementById('patternCancelBtn').addEventListener('click', () => {
            patternConfigModal.classList.remove('active');
        });
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // Generate number function
        function generateNumber(docType) {
            const year = new Date().getFullYear();
            const month = String(new Date().getMonth() + 1).padStart(2, '0');
            const day = String(new Date().getDate()).padStart(2, '0');
            
            // Get next sequence (simulated - in real app, fetch from server)
            const prefix = docType === 'ordinance' ? 'QC-ORD' : 'QC-RES';
            const sequence = Math.floor(Math.random() * 100) + 1;
            
            const number = `${prefix}-${year}-${String(sequence).padStart(4, '0')}`;
            document.getElementById('modalReferenceNumber').value = number;
            updateNumberPreview(number, docType);
        }
        
        // Update number preview
        function updateNumberPreview(number, docType) {
            const preview = document.getElementById('numberPreview');
            const typeColor = docType === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
            
            preview.innerHTML = `
                <div style="text-align: center;">
                    <div style="font-size: 1.5rem; font-weight: bold; color: ${typeColor}; margin-bottom: 10px;">
                        ${number}
                    </div>
                    <div style="display: flex; justify-content: center; gap: 10px; font-size: 0.9rem; color: var(--gray);">
                        <span><i class="fas fa-file-alt"></i> ${docType === 'ordinance' ? 'Ordinance' : 'Resolution'}</span>
                        <span><i class="fas fa-calendar"></i> ${new Date().getFullYear()}</span>
                        <span><i class="fas fa-hashtag"></i> Official Reference</span>
                    </div>
                </div>
            `;
        }
        
        // Number generation buttons
        document.getElementById('generateNumberBtn').addEventListener('click', function() {
            const docType = document.getElementById('modalDocumentType').value;
            generateNumber(docType);
        });
        
        document.getElementById('suggestNumberBtn').addEventListener('click', function() {
            const docType = document.getElementById('modalDocumentType').value;
            const year = new Date().getFullYear();
            
            // Generate multiple suggestions
            const suggestions = [];
            const prefix = docType === 'ordinance' ? 'QC-ORD' : 'QC-RES';
            
            for (let i = 1; i <= 3; i++) {
                const seq = Math.floor(Math.random() * 50) + 1;
                suggestions.push(`${prefix}-${year}-${String(seq).padStart(4, '0')}`);
            }
            
            // Show suggestions
            alert('Suggested numbers:\n' + suggestions.join('\n'));
        });
        
        document.getElementById('validateNumberBtn').addEventListener('click', function() {
            const number = document.getElementById('modalReferenceNumber').value;
            
            // Basic validation
            if (!number) {
                alert('Please enter a reference number first.');
                return;
            }
            
            // Check format (basic pattern)
            const pattern = /^QC-(ORD|RES|REG)-\d{4}-?\d*$/i;
            if (pattern.test(number)) {
                alert('✓ Reference number format is valid.');
            } else {
                alert('⚠ Reference number format appears invalid. Please check the pattern.');
            }
        });
        
        // Update number preview on input
        document.getElementById('modalReferenceNumber').addEventListener('input', function() {
            const number = this.value;
            const docType = document.getElementById('modalDocumentType').value;
            
            if (number) {
                updateNumberPreview(number, docType);
            }
        });
        
        // Pattern Configuration Modal
        const patternConfigModal = document.getElementById('patternConfigModal');
        const addPatternBtn = document.getElementById('addPatternBtn');
        const editPatternBtns = document.querySelectorAll('.edit-pattern-btn');
        
        // Add new pattern
        addPatternBtn.addEventListener('click', function() {
            // Reset form
            document.getElementById('patternConfigForm').reset();
            document.getElementById('configYear').value = new Date().getFullYear();
            document.getElementById('configSequence').value = 1;
            document.getElementById('configPattern').value = 'QC-TYPE-YYYY-SSSS';
            
            // Open modal
            patternConfigModal.classList.add('active');
            updatePatternPreview();
        });
        
        // Edit pattern
        editPatternBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const prefix = this.getAttribute('data-prefix');
                const docType = this.getAttribute('data-doc-type');
                const year = this.getAttribute('data-year');
                const sequence = this.getAttribute('data-sequence');
                const pattern = this.getAttribute('data-pattern');
                const desc = this.getAttribute('data-desc');
                
                // Fill form
                document.getElementById('configPrefix').value = prefix;
                document.getElementById('configDocType').value = docType;
                document.getElementById('configYear').value = year;
                document.getElementById('configSequence').value = sequence;
                document.getElementById('configPattern').value = pattern;
                document.getElementById('configDescription').value = desc;
                
                // Open modal
                patternConfigModal.classList.add('active');
                updatePatternPreview();
            });
        });
        
        // Delete pattern
        document.querySelectorAll('.delete-pattern-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const prefix = this.getAttribute('data-prefix');
                const year = this.getAttribute('data-year');
                
                if (confirm(`Are you sure you want to delete numbering pattern ${prefix}-${year}?`)) {
                    // In a real application, make an AJAX request to delete
                    alert('Pattern deletion would be processed here.');
                }
            });
        });
        
        // Update pattern preview
        function updatePatternPreview() {
            const prefix = document.getElementById('configPrefix').value || 'QC-TYPE';
            const docType = document.getElementById('configDocType').value;
            const year = document.getElementById('configYear').value || 'YYYY';
            const pattern = document.getElementById('configPattern').value || 'PATTERN';
            
            // Generate example number
            const example = pattern
                .replace(/QC/g, 'QC')
                .replace(/TYPE/g, docType === 'ordinance' ? 'ORD' : 'RES')
                .replace(/YYYY/g, year)
                .replace(/MM/g, '01')
                .replace(/DD/g, '01')
                .replace(/SSSS/g, '0001');
            
            document.getElementById('patternPreview').innerHTML = example;
        }
        
        // Pattern preview updates
        document.getElementById('configPrefix').addEventListener('input', updatePatternPreview);
        document.getElementById('configDocType').addEventListener('change', updatePatternPreview);
        document.getElementById('configYear').addEventListener('input', updatePatternPreview);
        document.getElementById('configPattern').addEventListener('input', updatePatternPreview);
        
        // Bulk numbering functionality
        const selectAllBtn = document.getElementById('selectAllBtn');
        const deselectAllBtn = document.getElementById('deselectAllBtn');
        const bulkCheckboxes = document.querySelectorAll('input[name="bulk_documents[]"]');
        
        selectAllBtn.addEventListener('click', function() {
            bulkCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateBulkPreview();
        });
        
        deselectAllBtn.addEventListener('click', function() {
            bulkCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateBulkPreview();
        });
        
        // Update bulk preview when checkboxes change
        bulkCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkPreview);
        });
        
        // Update bulk preview when scheme changes
        document.querySelector('select[name="bulk_scheme"]').addEventListener('change', updateBulkPreview);
        
        function updateBulkPreview() {
            const selectedCount = Array.from(bulkCheckboxes).filter(cb => cb.checked).length;
            const scheme = document.querySelector('select[name="bulk_scheme"]').value;
            const preview = document.getElementById('bulkPreview');
            
            if (selectedCount === 0 || !scheme) {
                preview.innerHTML = `
                    <i class="fas fa-mouse-pointer" style="font-size: 3rem; color: var(--gray-light); margin-bottom: 15px;"></i>
                    <p>Select documents and a numbering scheme to see preview.</p>
                `;
                return;
            }
            
            // Generate example numbers based on scheme
            let exampleNumbers = [];
            const year = new Date().getFullYear();
            
            for (let i = 1; i <= Math.min(3, selectedCount); i++) {
                let number;
                
                switch(scheme) {
                    case 'auto':
                        number = `QC-ORD-${year}-${String(i).padStart(4, '0')}`;
                        break;
                    case 'sequential':
                        const month = String(new Date().getMonth() + 1).padStart(2, '0');
                        const day = String(new Date().getDate()).padStart(2, '0');
                        number = `QC-ORD-${year}${month}${day}-${String(i).padStart(3, '0')}`;
                        break;
                    case 'yearly':
                        number = `QC-ORD-${year}-${String(i).padStart(4, '0')}`;
                        break;
                    case 'custom':
                        number = `QC-ORD-CUST-${String(i).padStart(3, '0')}`;
                        break;
                    default:
                        number = `QC-ORD-${year}-${String(i).padStart(4, '0')}`;
                }
                
                exampleNumbers.push(number);
            }
            
            preview.innerHTML = `
                <div style="text-align: left;">
                    <h4 style="color: var(--qc-blue); margin-bottom: 15px;">
                        <i class="fas fa-list-check"></i> Bulk Operation Preview
                    </h4>
                    
                    <div style="margin-bottom: 15px;">
                        <div><strong>Selected Documents:</strong> ${selectedCount}</div>
                        <div><strong>Numbering Scheme:</strong> ${scheme}</div>
                        <div><strong>Example Numbers:</strong></div>
                    </div>
                    
                    <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); font-family: monospace;">
                        ${exampleNumbers.map(num => `<div style="margin-bottom: 5px;">${num}</div>`).join('')}
                        ${selectedCount > 3 ? `<div style="color: var(--gray);">... and ${selectedCount - 3} more</div>` : ''}
                    </div>
                    
                    <div style="margin-top: 15px; padding: 10px; background: rgba(0, 51, 102, 0.05); border-radius: var(--border-radius);">
                        <i class="fas fa-info-circle" style="color: var(--qc-blue);"></i>
                        <span style="font-size: 0.9rem; color: var(--gray);">
                            ${selectedCount} document(s) will be assigned unique reference numbers.
                        </span>
                    </div>
                </div>
            `;
        }
        
        // Form submission handling
        document.getElementById('assignNumberForm').addEventListener('submit', function(e) {
            const number = document.getElementById('modalReferenceNumber').value;
            const reason = document.getElementById('modalReason').value;
            
            if (!number) {
                e.preventDefault();
                alert('Please enter a reference number.');
                return;
            }
            
            if (!reason) {
                e.preventDefault();
                alert('Please provide a reason for numbering.');
                return;
            }
            
            // Basic format validation
            const pattern = /^QC-(ORD|RES|REG)-\d{4}-?\d+$/i;
            if (!pattern.test(number)) {
                if (!confirm('Reference number format appears non-standard. Continue anyway?')) {
                    e.preventDefault();
                    return;
                }
            }
        });
        
        // Bulk form submission
        document.getElementById('bulkNumberingForm').addEventListener('submit', function(e) {
            const selectedCount = Array.from(bulkCheckboxes).filter(cb => cb.checked).length;
            const scheme = document.querySelector('select[name="bulk_scheme"]').value;
            
            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one document for bulk numbering.');
                return;
            }
            
            if (!scheme) {
                e.preventDefault();
                alert('Please select a numbering scheme.');
                return;
            }
            
            if (!confirm(`Are you sure you want to assign numbers to ${selectedCount} document(s) using the ${scheme} scheme?`)) {
                e.preventDefault();
                return;
            }
        });
        
        // Pattern form submission
        document.getElementById('patternConfigForm').addEventListener('submit', function(e) {
            const prefix = document.getElementById('configPrefix').value;
            const pattern = document.getElementById('configPattern').value;
            
            if (!prefix) {
                e.preventDefault();
                alert('Please enter a prefix.');
                return;
            }
            
            if (!pattern) {
                e.preventDefault();
                alert('Please enter a format pattern.');
                return;
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Add fade-in animations to cards
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
            
            // Observe dashboard cards
            document.querySelectorAll('.dashboard-card, .pattern-card').forEach(card => {
                observer.observe(card);
            });
            
            // Update pattern preview initially
            updatePatternPreview();
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + N to open number assignment modal
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                if (assignNumberBtns.length > 0) {
                    assignNumberBtns[0].click();
                }
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                assignNumberModal.classList.remove('active');
                patternConfigModal.classList.remove('active');
            }
        });
    </script>
</body>
</html>