<?php
// Start session
session_start();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$userName = $isLoggedIn ? ($_SESSION['firstname'] ?? 'User') : '';
$userRole = $isLoggedIn ? ($_SESSION['role'] ?? '') : '';

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Sample approved documents data (in real system, this would come from database)
$approvedDocuments = [
    [
        'id' => 1,
        'type' => 'Ordinance',
        'title' => 'QC Waste Management Ordinance 2026-015: Mandatory Recycling',
        'date' => 'February 7, 2026',
        'number' => 'SP-2026-015',
        'sponsor' => 'Councilor Joy Belmonte-Alimurung',
        'vote' => '28-2',
        'description' => 'Requires all households to separate recyclables, biodegradables, and residuals starting April 1, 2026. This ordinance aims to reduce landfill waste by 40% over the next three years and improve Quezon City\'s environmental sustainability.',
        'full_text' => 'SECTION 1. Title. This Ordinance shall be known as the "Quezon City Comprehensive Waste Management Ordinance of 2026." SECTION 2. Declaration of Policy. It is hereby declared the policy of the City of Quezon to adopt a systematic, comprehensive, and ecological waste management program...',
        'tags' => ['Environment', 'Waste Management', 'Public Safety'],
        'category' => 'Environment',
        'pdf_url' => '#'
    ],
    [
        'id' => 2,
        'type' => 'Resolution',
        'title' => 'QC Summer Youth Employment Program 2026',
        'date' => 'February 5, 2026',
        'number' => 'SP-2026-042',
        'sponsor' => 'Youth and Sports Development Committee',
        'budget' => '₱25M',
        'description' => 'Provides 2,500 summer jobs for QC youth aged 18-25. Applications open March 15 at all QC Public Employment Service Offices. This program aims to provide valuable work experience and financial support for students during summer break.',
        'full_text' => 'RESOLUTION NO. SP-2026-042 WHEREAS, the Quezon City Government recognizes the importance of providing employment opportunities for the youth during summer breaks...',
        'tags' => ['Youth', 'Employment', 'Education'],
        'category' => 'Education',
        'pdf_url' => '#'
    ],
    [
        'id' => 3,
        'type' => 'Ordinance',
        'title' => 'QC Public Transport Modernization Ordinance',
        'date' => 'January 30, 2026',
        'number' => 'SP-2026-011',
        'sponsor' => 'Transportation Committee',
        'description' => 'New regulations for jeepneys and public utility vehicles to improve safety and reduce emissions. All PUJs must comply with new standards by December 2026.',
        'full_text' => 'SECTION 1. Title. This Ordinance shall be known as the "Quezon City Public Transport Modernization Ordinance of 2026." SECTION 2. Purpose. To modernize public transportation systems, improve safety standards, and reduce environmental impact...',
        'tags' => ['Transportation', 'Safety', 'Modernization'],
        'category' => 'Transportation',
        'pdf_url' => '#'
    ],
    [
        'id' => 4,
        'type' => 'Resolution',
        'title' => 'QC Support for Local MSMEs Extended Through 2027',
        'date' => 'January 25, 2026',
        'number' => 'SP-2026-038',
        'sponsor' => 'Trade and Industry Committee',
        'description' => 'Continued support for micro, small, and medium enterprises with expanded loan programs and business development services.',
        'full_text' => 'RESOLUTION NO. SP-2026-038 WHEREAS, Micro, Small, and Medium Enterprises (MSMEs) are the backbone of Quezon City\'s economy...',
        'tags' => ['Business', 'Economy', 'MSME'],
        'category' => 'Economy',
        'pdf_url' => '#'
    ],
    [
        'id' => 5,
        'type' => 'Ordinance',
        'title' => 'QC Parks and Recreational Areas Ordinance',
        'date' => 'January 18, 2026',
        'number' => 'SP-2026-008',
        'sponsor' => 'Parks Development Committee',
        'description' => 'Extended operating hours for QC parks and new regulations for recreational activities in public spaces.',
        'full_text' => 'SECTION 1. Title. This Ordinance shall be known as the "Quezon City Parks and Recreational Areas Ordinance of 2026." SECTION 2. Operating Hours. All city parks shall be open from 5:00 AM to 10:00 PM daily...',
        'tags' => ['Parks', 'Recreation', 'Public Space'],
        'category' => 'Community',
        'pdf_url' => '#'
    ]
];

// Filter documents based on search/filter
$filteredDocuments = $approvedDocuments;
$searchTerm = '';
$selectedType = 'all';
$selectedCategory = 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['search'])) {
        $searchTerm = strtolower(trim($_POST['search']));
        if (!empty($searchTerm)) {
            $filteredDocuments = array_filter($approvedDocuments, function($doc) use ($searchTerm) {
                $title = strtolower($doc['title']);
                $desc = strtolower($doc['description']);
                $tags = implode(' ', array_map('strtolower', $doc['tags']));
                return strpos($title, $searchTerm) !== false || 
                       strpos($desc, $searchTerm) !== false ||
                       strpos($tags, $searchTerm) !== false;
            });
        }
    }
    
    if (isset($_POST['filter_type'])) {
        $selectedType = $_POST['filter_type'];
        if ($selectedType !== 'all') {
            $filteredDocuments = array_filter($filteredDocuments, function($doc) use ($selectedType) {
                return strtolower($doc['type']) === strtolower($selectedType);
            });
        }
    }
    
    if (isset($_POST['filter_category'])) {
        $selectedCategory = $_POST['filter_category'];
        if ($selectedCategory !== 'all') {
            $filteredDocuments = array_filter($filteredDocuments, function($doc) use ($selectedCategory) {
                return strtolower($doc['category']) === strtolower($selectedCategory);
            });
        }
    }
}

// Get document for modal view if requested
$viewDocument = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $docId = (int)$_GET['view'];
    foreach ($approvedDocuments as $doc) {
        if ($doc['id'] === $docId) {
            $viewDocument = $doc;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quezon City Ordinance Tracker | Public Information Portal</title>
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
        }

        html, body {
            height: 100%;
            width: 100%;
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            background-color: var(--off-white);
            color: var(--gray-dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* HEADER - GOVERNMENT STYLE */
        .government-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-md);
            border-bottom: 3px solid var(--qc-gold);
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
            padding: 15px 0;
        }

        .qc-logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .qc-seal-large {
            width: 70px;
            height: 70px;
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
            font-size: 32px;
            color: var(--qc-blue);
            z-index: 2;
        }

        .site-title-container h1 {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .site-title-container p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }

        .user-auth-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .login-button {
            padding: 12px 30px;
            background: linear-gradient(to right, var(--qc-gold), var(--qc-gold-dark));
            color: var(--qc-blue-dark);
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
            letter-spacing: 0.5px;
        }

        .login-button:hover {
            background: linear-gradient(to right, var(--qc-gold-dark), var(--qc-gold));
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
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

        /* HERO BANNER */
        .hero-banner {
            background: linear-gradient(135deg, rgba(0, 51, 102, 0.95) 0%, rgba(0, 34, 68, 0.95) 100%),
                        url('https://images.unsplash.com/photo-1589652717521-10c0d092dea9?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            background-position: center;
            color: var(--white);
            padding: 80px 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-banner::before {
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

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 800px;
            margin: 0 auto;
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
        }

        .hero-title {
            font-size: 2.8rem;
            font-weight: bold;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .hero-subtitle {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .qc-tagline {
            color: var(--qc-gold);
            font-style: italic;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 30px;
            padding: 10px 20px;
            border-left: 3px solid var(--qc-gold);
            background: rgba(0, 0, 0, 0.2);
            display: inline-block;
        }

        /* SEARCH & FILTER SECTION */
        .search-filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin: -40px auto 40px;
            max-width: 1200px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-light);
            position: relative;
            z-index: 10;
        }

        .search-container {
            margin-bottom: 25px;
        }

        .search-input-group {
            position: relative;
        }

        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.1rem;
        }

        .search-input {
            width: 100%;
            padding: 18px 20px 18px 55px;
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: inherit;
            background: var(--off-white);
            color: var(--gray-dark);
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
            background: var(--white);
        }

        .filters-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            position: relative;
        }

        .filter-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--qc-blue);
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }

        .filter-select {
            width: 100%;
            padding: 15px;
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-family: inherit;
            background: var(--off-white);
            color: var(--gray-dark);
            appearance: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
            background: var(--white);
        }

        .filter-select option {
            padding: 10px;
        }

        .search-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .search-button {
            padding: 14px 35px;
            background: linear-gradient(to right, var(--qc-blue), var(--qc-blue-dark));
            color: var(--white);
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
        }

        .search-button:hover {
            background: linear-gradient(to right, var(--qc-blue-dark), var(--qc-blue));
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .reset-button {
            padding: 14px 30px;
            background: transparent;
            color: var(--qc-blue);
            border: 2px solid var(--qc-blue);
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .reset-button:hover {
            background: var(--qc-blue);
            color: var(--white);
        }

        /* DOCUMENTS SECTION */
        .documents-section {
            padding: 40px 0;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--gray-light);
        }

        .section-title {
            font-size: 1.8rem;
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

        .document-count {
            background: var(--qc-blue);
            color: var(--white);
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 30px;
        }

        .document-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
            border: 1px solid var(--gray-light);
            border-top: 4px solid var(--qc-green);
        }

        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--qc-blue);
        }

        .document-header {
            padding: 25px;
            position: relative;
        }

        .document-badge {
            position: absolute;
            top: 25px;
            right: 25px;
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .badge-ordinance {
            background: linear-gradient(to right, var(--qc-green), var(--qc-green-dark));
            color: var(--white);
        }

        .badge-resolution {
            background: linear-gradient(to right, var(--qc-blue), var(--qc-blue-dark));
            color: var(--white);
        }

        .document-date {
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }

        .document-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--qc-blue);
            margin-bottom: 15px;
            line-height: 1.4;
        }

        .document-description {
            color: var(--gray-dark);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .document-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px 0;
            border-top: 1px solid var(--gray-light);
            border-bottom: 1px solid var(--gray-light);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray);
            font-size: 0.85rem;
        }

        .meta-item i {
            color: var(--qc-blue);
        }

        .document-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }

        .tag {
            background: var(--off-white);
            color: var(--qc-blue);
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }

        .tag:hover {
            background: var(--qc-blue);
            color: var(--white);
            border-color: var(--qc-blue);
        }

        .document-actions {
            padding: 20px 25px;
            background: var(--off-white);
            border-top: 1px solid var(--gray-light);
            display: flex;
            gap: 15px;
        }

        .document-button {
            flex: 1;
            padding: 12px 20px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
            text-align: center;
        }

        .view-button {
            background: linear-gradient(to right, var(--qc-blue), var(--qc-blue-dark));
            color: var(--white);
            border: none;
            cursor: pointer;
        }

        .view-button:hover {
            background: linear-gradient(to right, var(--qc-blue-dark), var(--qc-blue));
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .download-button {
            background: transparent;
            color: var(--qc-blue);
            border: 2px solid var(--qc-blue);
        }

        .download-button:hover {
            background: var(--qc-blue);
            color: var(--white);
        }

        /* NO RESULTS */
        .no-results {
            text-align: center;
            padding: 60px 20px;
            grid-column: 1 / -1;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            border: 2px dashed var(--gray-light);
        }

        .no-results-icon {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 20px;
        }

        .no-results h3 {
            color: var(--qc-blue);
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .no-results p {
            color: var(--gray);
            max-width: 500px;
            margin: 0 auto;
        }

        /* SERVICES SECTION */
        .services-section {
            padding: 60px 0;
            background: linear-gradient(135deg, var(--off-white) 0%, var(--white) 100%);
            border-top: 1px solid var(--gray-light);
            border-bottom: 1px solid var(--gray-light);
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
        }

        .service-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            text-align: center;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }

        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--qc-blue);
        }

        .service-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 1.8rem;
        }

        .service-card h3 {
            color: var(--qc-blue);
            margin-bottom: 15px;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .service-card p {
            color: var(--gray-dark);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        /* FOOTER */
        .government-footer {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 60px 0 30px;
            position: relative;
            overflow: hidden;
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 25px;
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
            width: 50px;
            height: 3px;
            background: var(--qc-gold);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 15px;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95rem;
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

        .qc-footer-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .qc-footer-logo .logo {
            width: 50px;
            height: 50px;
            font-size: 24px;
        }

        .footer-info p {
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.6;
            font-size: 0.95rem;
            margin-bottom: 15px;
        }

        .copyright {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            position: relative;
            z-index: 2;
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

        /* MODAL */
        .modal {
            display: <?php echo $viewDocument ? 'flex' : 'none'; ?>;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: var(--shadow-xl);
            border: 2px solid var(--qc-gold);
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--qc-blue);
            color: var(--white);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1001;
            transition: all 0.3s ease;
        }

        .close-modal:hover {
            background: var(--red);
            transform: rotate(90deg);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 30px;
            border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
        }

        .modal-document-title {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 15px;
            line-height: 1.3;
        }

        .modal-document-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
        }

        .modal-document-body {
            padding: 30px;
            line-height: 1.8;
            color: var(--gray-dark);
        }

        .modal-actions {
            padding: 25px 30px;
            background: var(--off-white);
            border-top: 1px solid var(--gray-light);
            display: flex;
            gap: 15px;
            border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
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

        /* RESPONSIVE DESIGN */
        @media (max-width: 1200px) {
            .documents-grid {
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .hero-title {
                font-size: 2.2rem;
            }
            
            .main-header-content {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .qc-logo-container {
                justify-content: center;
            }
            
            .search-filter-section {
                margin: -30px auto 30px;
            }
            
            .documents-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 1.8rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
            
            .section-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .search-actions {
                flex-direction: column;
            }
            
            .document-actions {
                flex-direction: column;
            }
            
            .user-auth-section {
                flex-direction: column;
                gap: 15px;
            }
            
            .user-welcome-panel {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 0 15px;
            }
            
            .hero-banner {
                padding: 50px 0;
            }
            
            .search-filter-section {
                padding: 20px;
                margin: -20px auto 20px;
            }
            
            .document-card {
                margin: 0 -15px;
                border-radius: 0;
                border-left: none;
                border-right: none;
            }
            
            .modal-content {
                width: 95%;
                padding: 0;
            }
            
            .services-grid {
                grid-template-columns: 1fr;
            }
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
        
        <div class="container">
            <div class="main-header-content">
                <div class="qc-logo-container">
                    <div class="qc-seal-large">
                        <i class="fas fa-landmark seal-icon-large"></i>
                    </div>
                    <div class="site-title-container">
                        <h1>Quezon City Ordinance Tracker</h1>
                        <p>Official Public Information Portal | Approved Ordinances & Resolutions</p>
                    </div>
                </div>
                
                <div class="user-auth-section">
                    <?php if($isLoggedIn): ?>
                        <div class="user-welcome-panel">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($userName, 0, 1)); ?>
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo htmlspecialchars($userName); ?></div>
                                <div class="user-role"><?php echo htmlspecialchars($userRole); ?></div>
                            </div>
                        </div>
                        <a href="?logout=true" class="logout-button">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    <?php else: ?>
                        <a href="auth/login.php" class="login-button">
                            <i class="fas fa-sign-in-alt"></i> Government Login
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Banner -->
    <section class="hero-banner">
        <div class="container">
            <div class="hero-content">
                <div class="system-badge">PUBLIC INFORMATION PORTAL</div>
                <h1 class="hero-title">Quezon City Official Announcements</h1>
                <p class="hero-subtitle">
                    Stay informed about new ordinances and resolutions approved by the Quezon City Council. 
                    Official public announcements for all residents and stakeholders.
                </p>
                <div class="qc-tagline">"The Pride of the Filipino Nation"</div>
            </div>
        </div>
    </section>

    <!-- Search & Filter Section -->
    <div class="container">
        <form method="POST" action="" class="search-filter-section slide-in">
            <div class="search-container">
                <div class="search-input-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" 
                           name="search" 
                           class="search-input" 
                           placeholder="Search for ordinances, resolutions, or topics..." 
                           value="<?php echo htmlspecialchars($searchTerm); ?>"
                           aria-label="Search documents">
                </div>
            </div>
            
            <div class="filters-container">
                <div class="filter-group">
                    <label class="filter-label">Document Type</label>
                    <select name="filter_type" class="filter-select">
                        <option value="all" <?php echo $selectedType === 'all' ? 'selected' : ''; ?>>All Document Types</option>
                        <option value="ordinance" <?php echo $selectedType === 'ordinance' ? 'selected' : ''; ?>>Ordinances</option>
                        <option value="resolution" <?php echo $selectedType === 'resolution' ? 'selected' : ''; ?>>Resolutions</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Category</label>
                    <select name="filter_category" class="filter-select">
                        <option value="all" <?php echo $selectedCategory === 'all' ? 'selected' : ''; ?>>All Categories</option>
                        <option value="Environment" <?php echo $selectedCategory === 'Environment' ? 'selected' : ''; ?>>Environment</option>
                        <option value="Education" <?php echo $selectedCategory === 'Education' ? 'selected' : ''; ?>>Education</option>
                        <option value="Transportation" <?php echo $selectedCategory === 'Transportation' ? 'selected' : ''; ?>>Transportation</option>
                        <option value="Economy" <?php echo $selectedCategory === 'Economy' ? 'selected' : ''; ?>>Economy</option>
                        <option value="Community" <?php echo $selectedCategory === 'Community' ? 'selected' : ''; ?>>Community</option>
                    </select>
                </div>
            </div>
            
            <div class="search-actions">
                <a href="?" class="reset-button">
                    <i class="fas fa-redo"></i> Reset Filters
                </a>
                <button type="submit" class="search-button">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Documents Section -->
    <div class="container documents-section">
        <div class="section-header fade-in">
            <h2 class="section-title">
                <i class="fas fa-file-alt"></i> Approved Documents
            </h2>
            <div class="document-count">
                Showing <?php echo count($filteredDocuments); ?> of <?php echo count($approvedDocuments); ?> Documents
            </div>
        </div>
        
        <?php if (count($filteredDocuments) > 0): ?>
            <div class="documents-grid">
                <?php foreach ($filteredDocuments as $document): ?>
                    <div class="document-card fade-in">
                        <div class="document-header">
                            <div class="document-badge <?php echo strtolower($document['type']) === 'ordinance' ? 'badge-ordinance' : 'badge-resolution'; ?>">
                                <?php echo htmlspecialchars($document['type']); ?>
                            </div>
                            <div class="document-date">
                                <i class="far fa-calendar"></i> Approved: <?php echo htmlspecialchars($document['date']); ?>
                            </div>
                            <h3 class="document-title"><?php echo htmlspecialchars($document['title']); ?></h3>
                            <p class="document-description"><?php echo htmlspecialchars($document['description']); ?></p>
                            
                            <div class="document-meta">
                                <div class="meta-item">
                                    <i class="fas fa-hashtag"></i>
                                    <span><?php echo htmlspecialchars($document['number']); ?></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-user-tie"></i>
                                    <span><?php echo htmlspecialchars($document['sponsor']); ?></span>
                                </div>
                                <?php if (isset($document['vote'])): ?>
                                <div class="meta-item">
                                    <i class="fas fa-vote-yea"></i>
                                    <span>Vote: <?php echo htmlspecialchars($document['vote']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($document['budget'])): ?>
                                <div class="meta-item">
                                    <i class="fas fa-dollar-sign"></i>
                                    <span><?php echo htmlspecialchars($document['budget']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="document-tags">
                                <?php foreach ($document['tags'] as $tag): ?>
                                    <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="document-actions">
                            <a href="?view=<?php echo $document['id']; ?>" class="document-button view-button">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                            <a href="#" class="document-button download-button" onclick="handleDownload(event)">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-results fade-in">
                <div class="no-results-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h3>No Documents Found</h3>
                <p>Try adjusting your search or filter criteria to find what you're looking for.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Services Section -->
    <section class="services-section">
        <div class="container">
            <div class="section-header fade-in">
                <h2 class="section-title">
                    <i class="fas fa-hands-helping"></i> QC Government Services
                </h2>
            </div>
            <div class="services-grid">
                <div class="service-card fade-in">
                    <div class="service-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <h3>QC Business Permits</h3>
                    <p>Apply for or renew your Quezon City business permit online or at QC Hall. Streamlined process for local businesses.</p>
                </div>
                <div class="service-card fade-in">
                    <div class="service-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <h3>QC Housing Programs</h3>
                    <p>Affordable housing programs for QC residents. Apply now for 2026 slots. Priority for low-income families.</p>
                </div>
                <div class="service-card fade-in">
                    <div class="service-icon">
                        <i class="fas fa-heartbeat"></i>
                    </div>
                    <h3>QC Health Services</h3>
                    <p>Free checkups, vaccinations, and health services at QC Health Centers. Comprehensive healthcare for all residents.</p>
                </div>
                <div class="service-card fade-in">
                    <div class="service-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>QC Scholarship Programs</h3>
                    <p>Educational assistance for QC students. Applications open March 2026. Support for academic excellence.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <div class="footer-info">
                        <div class="qc-footer-logo">
                            <div class="qc-seal-large" style="width: 50px; height: 50px;">
                                <i class="fas fa-city seal-icon-large" style="font-size: 24px;"></i>
                            </div>
                            <h3>Quezon City Government</h3>
                        </div>
                        <p>
                            Official public announcements portal for Quezon City ordinances and resolutions. 
                            Stay informed about new laws and policies affecting our 142 barangays.
                        </p>
                    </div>
                </div>
                
                <div class="footer-column">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href="?"><i class="fas fa-home"></i> Home</a></li>
                        <li><a href="#"><i class="fas fa-search"></i> Search Documents</a></li>
                        <li><a href="#"><i class="fas fa-newspaper"></i> Latest Announcements</a></li>
                        <li><a href="#"><i class="fas fa-download"></i> Download Forms</a></li>
                        <li><a href="#"><i class="fas fa-map-marked-alt"></i> QC Barangay Directory</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Government Contacts</h3>
                    <ul class="footer-links">
                        <li><a href="#"><i class="fas fa-envelope"></i> ordinances@quezoncity.gov.ph</a></li>
                        <li><a href="#"><i class="fas fa-phone"></i> QC Action Center: 122</a></li>
                        <li><a href="#"><i class="fas fa-building"></i> QC City Hall: (02) 8988-4242</a></li>
                        <li><a href="#"><i class="fas fa-map-marker-alt"></i> QC Hall, Elliptical Road</a></li>
                        <li><a href="#"><i class="fas fa-clock"></i> Mon-Fri, 8AM-5PM</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                <p>&copy; 2026 Quezon City Government. All approved ordinances and resolutions are official public records.</p>
                <p style="margin-top: 10px;">This portal displays only approved and enacted documents of Quezon City.</p>
                <div class="security-badge">
                    <i class="fas fa-shield-alt"></i>
                    Security Level: High | System Version: 3.2.1
                </div>
            </div>
        </div>
    </footer>

    <!-- Document View Modal -->
    <?php if ($viewDocument): ?>
    <div class="modal" id="documentModal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal()">&times;</button>
            
            <div class="modal-header">
                <h2 class="modal-document-title"><?php echo htmlspecialchars($viewDocument['title']); ?></h2>
                
                <div class="modal-document-meta">
                    <span><i class="far fa-calendar"></i> Approved: <?php echo htmlspecialchars($viewDocument['date']); ?></span>
                    <span><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($viewDocument['number']); ?></span>
                    <span><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($viewDocument['sponsor']); ?></span>
                    <?php if (isset($viewDocument['vote'])): ?>
                        <span><i class="fas fa-vote-yea"></i> Vote: <?php echo htmlspecialchars($viewDocument['vote']); ?></span>
                    <?php endif; ?>
                    <?php if (isset($viewDocument['budget'])): ?>
                        <span><i class="fas fa-dollar-sign"></i> Budget: <?php echo htmlspecialchars($viewDocument['budget']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="modal-document-body">
                <p><?php echo htmlspecialchars($viewDocument['full_text']); ?></p>
            </div>
            
            <div class="modal-actions">
                <a href="#" class="document-button download-button" onclick="handleDownload(event)">
                    <i class="fas fa-download"></i> Download PDF
                </a>
                <button class="document-button view-button" onclick="closeModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
        
        // Modal functionality
        function openModal(documentId) {
            window.location.href = '?view=' + documentId;
        }
        
        function closeModal() {
            window.location.href = '?';
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.querySelector('.modal');
            if (modal && event.target === modal) {
                closeModal();
            }
        });
        
        // Search functionality with Enter key
        document.querySelector('.search-input')?.addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.form.submit();
            }
        });
        
        // Handle PDF download
        function handleDownload(event) {
            event.preventDefault();
            const modal = document.getElementById('documentModal');
            if (modal && modal.style.display === 'flex') {
                // If viewing in modal
                alert('In a production system, this would download the full PDF document of: ' + document.querySelector('.modal-document-title').textContent);
            } else {
                // If downloading from card
                const card = event.target.closest('.document-card');
                const title = card.querySelector('.document-title').textContent;
                alert('In a production system, this would download the PDF document: ' + title);
            }
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
        
        // Observe all document cards and service cards
        document.querySelectorAll('.document-card, .service-card').forEach(card => {
            observer.observe(card);
        });
        
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if(targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if(targetElement) {
                    window.scrollTo({
                        top: targetElement.offsetTop - 100,
                        behavior: 'smooth'
                    });
                }
            });
        });
        
        // Form validation
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const searchInput = this.querySelector('input[name="search"]');
            if (searchInput && searchInput.value.trim().length < 2 && searchInput.value.trim() !== '') {
                alert('Please enter at least 2 characters for search.');
                e.preventDefault();
            }
        });
        
        // Initialize animations on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add fade-in class to initially visible elements
            const visibleElements = document.querySelectorAll('.government-header, .hero-banner, .search-filter-section');
            visibleElements.forEach(el => {
                el.classList.add('fade-in');
            });
        });
    </script>
</body>
</html>