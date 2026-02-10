<?php
// registration.php - Draft Registration Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission for draft registration
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

// Register a draft
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_draft'])) {
    try {
        $conn->beginTransaction();
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $registration_date = $_POST['registration_date'];
        $registration_notes = $_POST['registration_notes'] ?? '';
        $committee_review = isset($_POST['committee_review']) ? 1 : 0;
        $committee_id = $_POST['committee_id'] ?? null;
        $requires_signature = isset($_POST['requires_signature']) ? 1 : 0;
        
        // Generate registration number
        $prefix = ($document_type === 'ordinance') ? 'REG-ORD' : 'REG-RES';
        $year = date('Y');
        $month = date('m');
        
        // Get next sequence number for registration
        $sequence_query = "SELECT COUNT(*) + 1 as next_num FROM draft_registrations 
                          WHERE YEAR(registration_date) = :year";
        $stmt = $conn->prepare($sequence_query);
        $stmt->bindParam(':year', $year);
        $stmt->execute();
        $result = $stmt->fetch();
        $sequence = str_pad($result['next_num'], 4, '0', STR_PAD_LEFT);
        
        $registration_number = "QC-$prefix-$year-$month-$sequence";
        
        // Insert registration record
        $query = "INSERT INTO draft_registrations 
                  (document_id, document_type, registration_number, registration_date, 
                   registration_notes, registered_by, committee_review_required, 
                   committee_id, requires_signature, status) 
                  VALUES (:doc_id, :doc_type, :reg_number, :reg_date, :notes, 
                          :registered_by, :committee_review, :committee_id, 
                          :requires_signature, 'registered')";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->bindParam(':doc_type', $document_type);
        $stmt->bindParam(':reg_number', $registration_number);
        $stmt->bindParam(':reg_date', $registration_date);
        $stmt->bindParam(':notes', $registration_notes);
        $stmt->bindParam(':registered_by', $user_id);
        $stmt->bindParam(':committee_review', $committee_review);
        $stmt->bindParam(':committee_id', $committee_id);
        $stmt->bindParam(':requires_signature', $requires_signature);
        $stmt->execute();
        
        $registration_id = $conn->lastInsertId();
        
        // Update document status
        if ($document_type === 'ordinance') {
            $update_query = "UPDATE ordinances SET status = 'pending' WHERE id = :doc_id";
        } else {
            $update_query = "UPDATE resolutions SET status = 'pending' WHERE id = :doc_id";
        }
        
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->execute();
        
        // Assign to committee if required
        if ($committee_review && $committee_id) {
            $committee_query = "INSERT INTO document_committees 
                               (document_id, document_type, committee_id, assignment_type, 
                                assigned_by, status) 
                               VALUES (:doc_id, :doc_type, :committee_id, 'primary', 
                                       :assigned_by, 'pending')";
            $stmt = $conn->prepare($committee_query);
            $stmt->bindParam(':doc_id', $document_id);
            $stmt->bindParam(':doc_type', $document_type);
            $stmt->bindParam(':committee_id', $committee_id);
            $stmt->bindParam(':assigned_by', $user_id);
            $stmt->execute();
        }
        
        $conn->commit();
        
        // Log the action
        $doc_number = '';
        if ($document_type === 'ordinance') {
            $doc_query = "SELECT ordinance_number FROM ordinances WHERE id = :id";
            $stmt = $conn->prepare($doc_query);
            $stmt->bindParam(':id', $document_id);
            $stmt->execute();
            $doc = $stmt->fetch();
            $doc_number = $doc['ordinance_number'];
        } else {
            $doc_query = "SELECT resolution_number FROM resolutions WHERE id = :id";
            $stmt = $conn->prepare($doc_query);
            $stmt->bindParam(':id', $document_id);
            $stmt->execute();
            $doc = $stmt->fetch();
            $doc_number = $doc['resolution_number'];
        }
        
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'DRAFT_REGISTER', 'Registered draft: {$doc_number} with registration: {$registration_number}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Draft registered successfully! Registration Number: {$registration_number}";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error registering draft: " . $e->getMessage();
    }
}

// Archive a registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_registration'])) {
    try {
        $registration_id = $_POST['registration_id'];
        
        $query = "UPDATE draft_registrations SET status = 'archived' WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $registration_id);
        $stmt->execute();
        
        $success_message = "Registration archived successfully!";
        
    } catch (PDOException $e) {
        $error_message = "Error archiving registration: " . $e->getMessage();
    }
}

// Get available drafts for registration (drafts that aren't registered yet)
$drafts_query = "
    (
        SELECT 'ordinance' as doc_type, id, ordinance_number as doc_number, title, 
               description, status, created_at, created_by
        FROM ordinances 
        WHERE status = 'draft' 
        AND id NOT IN (SELECT document_id FROM draft_registrations WHERE document_type = 'ordinance')
        ORDER BY created_at DESC
    ) 
    UNION ALL 
    (
        SELECT 'resolution' as doc_type, id, resolution_number as doc_number, title, 
               description, status, created_at, created_by
        FROM resolutions 
        WHERE status = 'draft' 
        AND id NOT IN (SELECT document_id FROM draft_registrations WHERE document_type = 'resolution')
        ORDER BY created_at DESC
    ) 
    ORDER BY created_at DESC";

$drafts_stmt = $conn->query($drafts_query);
$available_drafts = $drafts_stmt->fetchAll();

// Get registered drafts
$registered_query = "
    SELECT dr.*, 
           CASE 
               WHEN dr.document_type = 'ordinance' THEN o.ordinance_number
               WHEN dr.document_type = 'resolution' THEN r.resolution_number
           END as document_number,
           CASE 
               WHEN dr.document_type = 'ordinance' THEN o.title
               WHEN dr.document_type = 'resolution' THEN r.title
           END as document_title,
           CASE 
               WHEN dr.document_type = 'ordinance' THEN o.status
               WHEN dr.document_type = 'resolution' THEN r.status
           END as document_status,
           u.first_name, u.last_name,
           c.committee_name
    FROM draft_registrations dr
    LEFT JOIN ordinances o ON dr.document_type = 'ordinance' AND dr.document_id = o.id
    LEFT JOIN resolutions r ON dr.document_type = 'resolution' AND dr.document_id = r.id
    LEFT JOIN users u ON dr.registered_by = u.id
    LEFT JOIN committees c ON dr.committee_id = c.id
    WHERE dr.status != 'archived'
    ORDER BY dr.registration_date DESC, dr.created_at DESC";

$registered_stmt = $conn->query($registered_query);
$registered_drafts = $registered_stmt->fetchAll();

// Get archived registrations
$archived_query = "
    SELECT dr.*, 
           CASE 
               WHEN dr.document_type = 'ordinance' THEN o.ordinance_number
               WHEN dr.document_type = 'resolution' THEN r.resolution_number
           END as document_number,
           CASE 
               WHEN dr.document_type = 'ordinance' THEN o.title
               WHEN dr.document_type = 'resolution' THEN r.title
           END as document_title,
           u.first_name, u.last_name
    FROM draft_registrations dr
    LEFT JOIN ordinances o ON dr.document_type = 'ordinance' AND dr.document_id = o.id
    LEFT JOIN resolutions r ON dr.document_type = 'resolution' AND dr.document_id = r.id
    LEFT JOIN users u ON dr.registered_by = u.id
    WHERE dr.status = 'archived'
    ORDER BY dr.updated_at DESC";

$archived_stmt = $conn->query($archived_query);
$archived_registrations = $archived_stmt->fetchAll();

// Get committees
$committees_query = "SELECT * FROM committees WHERE is_active = 1 ORDER BY committee_name";
$committees_stmt = $conn->query($committees_query);
$committees = $committees_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_drafts,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count
    FROM (
        SELECT status FROM ordinances 
        UNION ALL 
        SELECT status FROM resolutions
    ) as all_docs";

$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

$registrations_count = count($registered_drafts);
$archived_count = count($archived_registrations);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Registration | QC Ordinance Tracker</title>
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
            --orange: #ED8936;
            --purple: #805AD5;
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
        
        /* TABS NAVIGATION */
        .tabs-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 10px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: none;
            color: var(--gray);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border-radius: var(--border-radius);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .tab-button:hover {
            background: var(--off-white);
            color: var(--qc-blue);
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            box-shadow: var(--shadow-md);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* FORM STYLES */
        .registration-form-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .registration-form-container {
                grid-template-columns: 1fr;
            }
        }
        
        .form-main {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .form-sidebar {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: var(--qc-blue);
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .section-title i {
            color: var(--qc-gold);
            font-size: 1.1rem;
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
            min-height: 120px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .checkbox-input {
            width: 20px;
            height: 20px;
            accent-color: var(--qc-blue);
        }
        
        .checkbox-label {
            font-weight: 500;
            color: var(--gray-dark);
        }
        
        /* Draft Selection */
        .draft-selection {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-top: 10px;
        }
        
        .draft-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .draft-item:hover {
            background: var(--off-white);
        }
        
        .draft-item.selected {
            background: rgba(0, 51, 102, 0.05);
            border-left: 4px solid var(--qc-blue);
        }
        
        .draft-item:last-child {
            border-bottom: none;
        }
        
        .draft-radio {
            width: 20px;
            height: 20px;
            accent-color: var(--qc-blue);
        }
        
        .draft-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: var(--white);
        }
        
        .draft-icon.ordinance {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
        }
        
        .draft-icon.resolution {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
        }
        
        .draft-info {
            flex: 1;
        }
        
        .draft-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .draft-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .draft-number {
            font-weight: 600;
            color: var(--qc-gold);
        }
        
        .draft-author {
            color: var(--qc-blue);
        }
        
        .draft-date {
            color: var(--gray);
        }
        
        /* Committee Selection */
        .committee-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .committee-option {
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .committee-option:hover {
            border-color: var(--qc-blue);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .committee-option.selected {
            border-color: var(--qc-gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .committee-radio {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 20px;
            height: 20px;
            accent-color: var(--qc-gold);
        }
        
        .committee-name {
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
            font-size: 1.1rem;
        }
        
        .committee-code {
            display: inline-block;
            padding: 3px 10px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border-radius: 20px;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }
        
        .committee-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        /* Action Buttons */
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
        
        /* Registration Cards */
        .registration-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .registration-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .registration-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .registration-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--off-white);
        }
        
        .registration-number {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--qc-blue);
            background: rgba(0, 51, 102, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .registration-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-registered { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-archived { background: #f3f4f6; color: #6b7280; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .registration-details {
            margin-bottom: 20px;
        }
        
        .registration-detail {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .detail-label {
            color: var(--gray);
            font-weight: 500;
        }
        
        .detail-value {
            color: var(--gray-dark);
            font-weight: 600;
            text-align: right;
        }
        
        .registration-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        .action-btn {
            flex: 1;
            padding: 8px 15px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .action-btn.view { background: var(--qc-blue); color: var(--white); }
        .action-btn.edit { background: var(--qc-gold); color: var(--qc-blue-dark); }
        .action-btn.archive { background: var(--gray-light); color: var(--gray-dark); }
        .action-btn.restore { background: var(--qc-green); color: var(--white); }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .empty-icon {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 20px;
        }
        
        .empty-title {
            font-size: 1.5rem;
            color: var(--gray-dark);
            margin-bottom: 10px;
        }
        
        .empty-description {
            color: var(--gray);
            margin-bottom: 30px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
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
            
            .registration-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .tabs-navigation {
                flex-direction: column;
            }
            
            .form-actions, .registration-actions {
                flex-direction: column;
            }
            
            .btn, .action-btn {
                width: 100%;
                justify-content: center;
            }
            
            .committee-options {
                grid-template-columns: 1fr;
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
                    <p>Draft Registration Module | Ordinance & Resolution Registration</p>
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
                        <i class="fas fa-registered"></i> Registration Module
                    </h3>
                    <p class="sidebar-subtitle">Draft Registration Interface</p>
                </div>
                
                <?php 
                // Include sidebar.php from the same directory
                $sidebar_path = 'sidebar.php';
                if (file_exists($sidebar_path)) {
                    include $sidebar_path;
                } else {
                    // Fallback sidebar content
                    echo '<ul class="sidebar-menu">';
                    echo '<li><a href="creation.php"><i class="fas fa-file-contract"></i> Creation Module</a></li>';
                    echo '<li><a href="registration.php" class="active"><i class="fas fa-registered"></i> Registration</a></li>';
                    echo '<li><a href="my_documents.php"><i class="fas fa-folder-open"></i> My Documents</a></li>';
                    echo '<li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>';
                    echo '</ul>';
                }
                ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="registration-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">DRAFT REGISTRATION MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-registered"></i>
                            </div>
                            <div class="module-title">
                                <h1>Draft Registration System</h1>
                                <p class="module-subtitle">
                                    Register and manage ordinance and resolution drafts. Track registration status, 
                                    assign committee reviews, and maintain official records for Quezon City legislation.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($available_drafts); ?></h3>
                                    <p>Drafts Ready for Registration</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $registrations_count; ?></h3>
                                    <p>Active Registrations</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-archive"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $archived_count; ?></h3>
                                    <p>Archived Registrations</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($committees); ?></h3>
                                    <p>Available Committees</p>
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

                <!-- Tabs Navigation -->
                <div class="tabs-navigation fade-in">
                    <button class="tab-button active" data-tab="register">
                        <i class="fas fa-plus-circle"></i>
                        Register New Draft
                    </button>
                    <button class="tab-button" data-tab="active">
                        <i class="fas fa-list-check"></i>
                        Active Registrations
                        <?php if ($registrations_count > 0): ?>
                        <span style="background: var(--qc-gold); color: var(--qc-blue-dark); padding: 2px 8px; border-radius: 10px; font-size: 0.8rem;">
                            <?php echo $registrations_count; ?>
                        </span>
                        <?php endif; ?>
                    </button>
                    <button class="tab-button" data-tab="archived">
                        <i class="fas fa-archive"></i>
                        Archived
                        <?php if ($archived_count > 0): ?>
                        <span style="background: var(--gray); color: var(--white); padding: 2px 8px; border-radius: 10px; font-size: 0.8rem;">
                            <?php echo $archived_count; ?>
                        </span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- Tab: Register New Draft -->
                <div class="tab-content active" id="registerTab">
                    <form method="POST" action="" class="fade-in">
                        <input type="hidden" name="register_draft" value="1">
                        
                        <div class="registration-form-container">
                            <!-- Main Form -->
                            <div class="form-main">
                                <!-- Draft Selection -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class="fas fa-file-signature"></i>
                                        1. Select Draft to Register
                                    </h3>
                                    
                                    <?php if (empty($available_drafts)): ?>
                                    <div class="empty-state" style="margin-top: 20px; padding: 40px 20px;">
                                        <i class="fas fa-inbox-empty empty-icon"></i>
                                        <h3 class="empty-title">No Drafts Available</h3>
                                        <p class="empty-description">
                                            There are no drafts ready for registration. Please create or check existing drafts.
                                        </p>
                                        <a href="draft_creation.php" class="btn btn-primary">
                                            <i class="fas fa-plus"></i> Create New Draft
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <div class="form-group">
                                        <label class="form-label required">Available Drafts</label>
                                        <div class="draft-selection">
                                            <?php foreach ($available_drafts as $draft): 
                                                $type_class = $draft['doc_type'] === 'ordinance' ? 'ordinance' : 'resolution';
                                                $type_icon = $draft['doc_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                                                
                                                // Get author info
                                                $author_query = "SELECT u.first_name, u.last_name 
                                                                FROM document_authors da 
                                                                JOIN users u ON da.user_id = u.id 
                                                                WHERE da.document_id = :doc_id 
                                                                AND da.document_type = :doc_type 
                                                                LIMIT 1";
                                                $author_stmt = $conn->prepare($author_query);
                                                $author_stmt->bindParam(':doc_id', $draft['id']);
                                                $author_stmt->bindParam(':doc_type', $draft['doc_type']);
                                                $author_stmt->execute();
                                                $author = $author_stmt->fetch();
                                            ?>
                                            <div class="draft-item" onclick="selectDraft(<?php echo $draft['id']; ?>, '<?php echo $draft['doc_type']; ?>')">
                                                <input type="radio" name="document_id" value="<?php echo $draft['id']; ?>" 
                                                       class="draft-radio" data-doc-type="<?php echo $draft['doc_type']; ?>" required>
                                                <div class="draft-icon <?php echo $type_class; ?>">
                                                    <i class="fas <?php echo $type_icon; ?>"></i>
                                                </div>
                                                <div class="draft-info">
                                                    <div class="draft-title"><?php echo htmlspecialchars($draft['title']); ?></div>
                                                    <div class="draft-meta">
                                                        <span class="draft-number"><?php echo htmlspecialchars($draft['doc_number']); ?></span>
                                                        <?php if ($author): ?>
                                                        <span class="draft-author"><?php echo htmlspecialchars($author['first_name'] . ' ' . $author['last_name']); ?></span>
                                                        <?php endif; ?>
                                                        <span class="draft-date"><?php echo date('M d, Y', strtotime($draft['created_at'])); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <input type="hidden" name="document_type" id="documentType" value="">
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Registration Details -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class="fas fa-calendar-alt"></i>
                                        2. Registration Details
                                    </h3>
                                    
                                    <div class="form-group">
                                        <label for="registration_date" class="form-label required">Registration Date</label>
                                        <input type="date" id="registration_date" name="registration_date" 
                                               class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="registration_notes" class="form-label">Registration Notes</label>
                                        <textarea id="registration_notes" name="registration_notes" 
                                                  class="form-control" 
                                                  placeholder="Add any notes or special instructions for this registration..."
                                                  rows="4"></textarea>
                                    </div>
                                </div>

                                <!-- Committee Assignment -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class="fas fa-users"></i>
                                        3. Committee Assignment (Optional)
                                    </h3>
                                    
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="committee_review" name="committee_review" 
                                                   class="checkbox-input" onchange="toggleCommitteeSelection()">
                                            <label for="committee_review" class="checkbox-label">
                                                <strong>Require Committee Review</strong>
                                            </label>
                                        </div>
                                        <div class="committee-selection" id="committeeSelection" style="display: none;">
                                            <label class="form-label">Select Committee</label>
                                            <div class="committee-options">
                                                <?php foreach ($committees as $committee): ?>
                                                <div class="committee-option" onclick="selectCommittee(<?php echo $committee['id']; ?>)">
                                                    <input type="radio" name="committee_id" 
                                                           value="<?php echo $committee['id']; ?>" 
                                                           class="committee-radio" id="committee_<?php echo $committee['id']; ?>">
                                                    <div class="committee-name"><?php echo htmlspecialchars($committee['committee_name']); ?></div>
                                                    <div class="committee-code"><?php echo htmlspecialchars($committee['committee_code']); ?></div>
                                                    <div class="committee-description">
                                                        <?php echo htmlspecialchars($committee['description']); ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Signature Requirements -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class="fas fa-signature"></i>
                                        4. Signature Requirements
                                    </h3>
                                    
                                    <div class="form-group">
                                        <div class="checkbox-group">
                                            <input type="checkbox" id="requires_signature" name="requires_signature" 
                                                   class="checkbox-input">
                                            <label for="requires_signature" class="checkbox-label">
                                                <strong>Requires Official Signature</strong> (For special documents requiring manual signatures)
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <div class="form-actions">
                                    <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                        <i class="fas fa-eraser"></i> Clear Form
                                    </button>
                                    <button type="submit" class="btn btn-success" id="submitBtn">
                                        <i class="fas fa-registered"></i> Register Draft
                                    </button>
                                </div>
                            </div>

                            <!-- Sidebar -->
                            <div class="form-sidebar">
                                <!-- Quick Statistics -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class="fas fa-chart-pie"></i>
                                        Registration Statistics
                                    </h3>
                                    
                                    <div style="font-size: 0.9rem;">
                                        <div style="margin-bottom: 15px;">
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                                <span>Total Drafts:</span>
                                                <strong><?php echo $stats['total_drafts']; ?></strong>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                                <span>In Draft:</span>
                                                <strong><?php echo $stats['draft_count']; ?></strong>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                                <span>Pending Review:</span>
                                                <strong><?php echo $stats['pending_count']; ?></strong>
                                            </div>
                                            <div style="display: flex; justify-content: space-between;">
                                                <span>Approved:</span>
                                                <strong><?php echo $stats['approved_count']; ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Registration Guidelines -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class="fas fa-info-circle"></i>
                                        Registration Guidelines
                                    </h3>
                                    
                                    <div style="font-size: 0.9rem; color: var(--gray);">
                                        <p style="margin-bottom: 10px;"><strong>Before registering a draft:</strong></p>
                                        <ul style="padding-left: 20px; margin-bottom: 15px;">
                                            <li>Ensure all required fields are completed</li>
                                            <li>Verify document content and formatting</li>
                                            <li>Assign appropriate authors and sponsors</li>
                                            <li>Attach all supporting documents</li>
                                            <li>Select appropriate committee for review if needed</li>
                                        </ul>
                                        <p><strong>Note:</strong> Once registered, drafts move to the review phase and cannot be edited without approval.</p>
                                    </div>
                                </div>

                                <!-- Recent Activity -->
                                <div class="form-section">
                                    <h3 class="section-title">
                                        <i class="fas fa-history"></i>
                                        Recent Registrations
                                    </h3>
                                    
                                    <div style="font-size: 0.85rem;">
                                        <?php 
                                        $recent_query = "SELECT dr.registration_number, dr.document_type, dr.registration_date,
                                                        u.first_name, u.last_name
                                                        FROM draft_registrations dr
                                                        JOIN users u ON dr.registered_by = u.id
                                                        WHERE dr.status = 'registered'
                                                        ORDER BY dr.created_at DESC
                                                        LIMIT 3";
                                        $recent_stmt = $conn->query($recent_query);
                                        $recent = $recent_stmt->fetchAll();
                                        
                                        if (empty($recent)): ?>
                                        <p style="color: var(--gray); font-style: italic;">No recent registrations</p>
                                        <?php else: ?>
                                        <?php foreach ($recent as $item): ?>
                                        <div style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid var(--gray-light);">
                                            <div style="font-weight: 600; color: var(--qc-blue);">
                                                <?php echo htmlspecialchars($item['registration_number']); ?>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                                                <span style="color: var(--gray);">
                                                    <?php echo ucfirst($item['document_type']); ?>
                                                </span>
                                                <span style="color: var(--gray); font-size: 0.8rem;">
                                                    <?php echo date('M d', strtotime($item['registration_date'])); ?>
                                                </span>
                                            </div>
                                            <div style="color: var(--gray); font-size: 0.8rem; margin-top: 3px;">
                                                Registered by: <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Tab: Active Registrations -->
                <div class="tab-content" id="activeTab">
                    <?php if (empty($registered_drafts)): ?>
                    <div class="empty-state fade-in">
                        <i class="fas fa-inbox empty-icon"></i>
                        <h3 class="empty-title">No Active Registrations</h3>
                        <p class="empty-description">
                            There are no active draft registrations. Register a draft to get started.
                        </p>
                        <button class="btn btn-primary" onclick="switchTab('register')">
                            <i class="fas fa-plus"></i> Register New Draft
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="registration-grid fade-in">
                        <?php foreach ($registered_drafts as $registration): 
                            $document_type = $registration['document_type'];
                            $type_icon = $document_type === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                            $type_color = $document_type === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                        ?>
                        <div class="registration-card">
                            <div class="registration-header">
                                <div class="registration-number">
                                    <?php echo htmlspecialchars($registration['registration_number']); ?>
                                </div>
                                <div class="registration-status status-<?php echo $registration['status']; ?>">
                                    <?php echo ucfirst($registration['status']); ?>
                                </div>
                            </div>
                            
                            <div class="registration-details">
                                <div class="registration-detail">
                                    <span class="detail-label">Document:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($registration['document_number']); ?></span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Title:</span>
                                    <span class="detail-value" style="max-width: 200px; text-align: right;">
                                        <?php echo htmlspecialchars($registration['document_title']); ?>
                                    </span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Type:</span>
                                    <span class="detail-value">
                                        <i class="fas <?php echo $type_icon; ?>" style="color: <?php echo $type_color; ?>;"></i>
                                        <?php echo ucfirst($document_type); ?>
                                    </span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Registered Date:</span>
                                    <span class="detail-value"><?php echo date('M d, Y', strtotime($registration['registration_date'])); ?></span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Registered By:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($registration['first_name'] . ' ' . $registration['last_name']); ?></span>
                                </div>
                                <?php if ($registration['committee_name']): ?>
                                <div class="registration-detail">
                                    <span class="detail-label">Committee:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($registration['committee_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($registration['requires_signature']): ?>
                                <div class="registration-detail">
                                    <span class="detail-label">Signature:</span>
                                    <span class="detail-value" style="color: var(--qc-gold);">
                                        <i class="fas fa-signature"></i> Required
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="registration-actions">
                                <button class="action-btn view" onclick="viewRegistration(<?php echo $registration['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn edit" onclick="editRegistration(<?php echo $registration['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <form method="POST" action="" style="flex: 1;" onsubmit="return confirm('Are you sure you want to archive this registration?')">
                                    <input type="hidden" name="archive_registration" value="1">
                                    <input type="hidden" name="registration_id" value="<?php echo $registration['id']; ?>">
                                    <button type="submit" class="action-btn archive">
                                        <i class="fas fa-archive"></i> Archive
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tab: Archived Registrations -->
                <div class="tab-content" id="archivedTab">
                    <?php if (empty($archived_registrations)): ?>
                    <div class="empty-state fade-in">
                        <i class="fas fa-archive empty-icon"></i>
                        <h3 class="empty-title">No Archived Registrations</h3>
                        <p class="empty-description">
                            There are no archived draft registrations. Archived registrations appear here.
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="registration-grid fade-in">
                        <?php foreach ($archived_registrations as $archived): 
                            $document_type = $archived['document_type'];
                            $type_icon = $document_type === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                            $type_color = $document_type === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                        ?>
                        <div class="registration-card" style="opacity: 0.8;">
                            <div class="registration-header">
                                <div class="registration-number">
                                    <?php echo htmlspecialchars($archived['registration_number']); ?>
                                </div>
                                <div class="registration-status status-archived">
                                    Archived
                                </div>
                            </div>
                            
                            <div class="registration-details">
                                <div class="registration-detail">
                                    <span class="detail-label">Document:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($archived['document_number']); ?></span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Title:</span>
                                    <span class="detail-value" style="max-width: 200px; text-align: right;">
                                        <?php echo htmlspecialchars($archived['document_title']); ?>
                                    </span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Type:</span>
                                    <span class="detail-value">
                                        <i class="fas <?php echo $type_icon; ?>" style="color: <?php echo $type_color; ?>;"></i>
                                        <?php echo ucfirst($document_type); ?>
                                    </span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Archived Date:</span>
                                    <span class="detail-value"><?php echo date('M d, Y', strtotime($archived['updated_at'])); ?></span>
                                </div>
                                <div class="registration-detail">
                                    <span class="detail-label">Archived By:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($archived['first_name'] . ' ' . $archived['last_name']); ?></span>
                                </div>
                            </div>
                            
                            <div class="registration-actions">
                                <button class="action-btn view" onclick="viewRegistration(<?php echo $archived['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn restore" onclick="restoreRegistration(<?php echo $archived['id']; ?>)">
                                    <i class="fas fa-undo"></i> Restore
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Draft Registration Module</h3>
                    <p>
                        Register and manage ordinance and resolution drafts. Track registration status, 
                        assign committee reviews, and maintain official records for Quezon City legislation.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="draft_creation.php"><i class="fas fa-file-contract"></i> Draft Creation</a></li>
                    <li><a href="templates.php"><i class="fas fa-file-alt"></i> Template Library</a></li>
                    <li><a href="my_documents.php"><i class="fas fa-folder-open"></i> My Documents</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Registration Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Video Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Draft Registration Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All registration activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Draft Registration Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // Tab functionality
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + 'Tab').classList.add('active');
            
            // Activate selected button
            document.querySelector(`.tab-button[data-tab="${tabName}"]`).classList.add('active');
            
            // Scroll to top of tab content
            document.getElementById(tabName + 'Tab').scrollIntoView({ behavior: 'smooth' });
        }
        
        // Add click events to tab buttons
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tabName = this.getAttribute('data-tab');
                switchTab(tabName);
            });
        });
        
        // Draft selection functionality
        function selectDraft(draftId, docType) {
            // Remove selected class from all drafts
            document.querySelectorAll('.draft-item').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Add selected class to clicked draft
            const selectedDraft = document.querySelector(`.draft-radio[value="${draftId}"]`).closest('.draft-item');
            selectedDraft.classList.add('selected');
            
            // Set the radio button as checked
            document.querySelector(`.draft-radio[value="${draftId}"]`).checked = true;
            
            // Update document type
            document.getElementById('documentType').value = docType;
            
            // Scroll to show selected draft
            selectedDraft.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        // Committee selection functionality
        function selectCommittee(committeeId) {
            // Remove selected class from all committees
            document.querySelectorAll('.committee-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked committee
            const selectedCommittee = document.querySelector(`.committee-radio[value="${committeeId}"]`).closest('.committee-option');
            selectedCommittee.classList.add('selected');
            
            // Set the radio button as checked
            document.querySelector(`.committee-radio[value="${committeeId}"]`).checked = true;
        }
        
        // Toggle committee selection
        function toggleCommitteeSelection() {
            const committeeCheckbox = document.getElementById('committee_review');
            const committeeSelection = document.getElementById('committeeSelection');
            
            if (committeeCheckbox.checked) {
                committeeSelection.style.display = 'block';
                committeeSelection.style.animation = 'fadeIn 0.3s ease-out';
            } else {
                committeeSelection.style.display = 'none';
                // Clear committee selection
                document.querySelectorAll('.committee-radio').forEach(radio => {
                    radio.checked = false;
                });
                document.querySelectorAll('.committee-option').forEach(option => {
                    option.classList.remove('selected');
                });
            }
        }
        
        // Clear form
        function clearForm() {
            if (confirm('Are you sure you want to clear the form? All unsaved changes will be lost.')) {
                document.querySelector('form').reset();
                document.querySelectorAll('.draft-item').forEach(item => {
                    item.classList.remove('selected');
                });
                document.querySelectorAll('.committee-option').forEach(option => {
                    option.classList.remove('selected');
                });
                document.getElementById('committeeSelection').style.display = 'none';
                document.getElementById('documentType').value = '';
            }
        }
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const documentId = document.querySelector('input[name="document_id"]:checked');
            const registrationDate = document.getElementById('registration_date').value;
            
            if (!documentId) {
                e.preventDefault();
                alert('Please select a draft to register.');
                return;
            }
            
            if (!registrationDate) {
                e.preventDefault();
                alert('Please select a registration date.');
                return;
            }
            
            // Check committee selection if committee review is required
            const committeeCheckbox = document.getElementById('committee_review');
            if (committeeCheckbox.checked) {
                const committeeId = document.querySelector('input[name="committee_id"]:checked');
                if (!committeeId) {
                    e.preventDefault();
                    alert('Please select a committee for review.');
                    return;
                }
            }
            
            // Show confirmation
            if (!confirm('Are you sure you want to register this draft? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
        
        // Registration actions
        function viewRegistration(registrationId) {
            alert('View registration details for ID: ' + registrationId);
            // In a real application, this would open a modal or redirect to a view page
        }
        
        function editRegistration(registrationId) {
            alert('Edit registration for ID: ' + registrationId);
            // In a real application, this would open an edit form
        }
        
        function restoreRegistration(registrationId) {
            if (confirm('Are you sure you want to restore this registration?')) {
                // In a real application, this would submit a restore request
                alert('Restoring registration ID: ' + registrationId);
                // Reload the page to show updated status
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        }
        
        // Auto-select today's date for registration
        document.addEventListener('DOMContentLoaded', function() {
            // Set max date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('registration_date').max = today;
            
            // Auto-select the first draft if available
            const firstDraft = document.querySelector('.draft-radio');
            if (firstDraft) {
                const draftId = firstDraft.value;
                const docType = firstDraft.getAttribute('data-doc-type');
                selectDraft(draftId, docType);
            }
            
            // Add animation to form elements
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
            
            // Observe registration cards
            document.querySelectorAll('.registration-card, .form-section').forEach(element => {
                observer.observe(element);
            });
            
            // Handle form errors
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('error')) {
                const error = urlParams.get('error');
                alert('Error: ' + decodeURIComponent(error));
            }
            
            if (urlParams.has('success')) {
                const success = urlParams.get('success');
                alert('Success: ' + decodeURIComponent(success));
            }
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>