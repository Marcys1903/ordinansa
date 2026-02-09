<?php
require('../vendor/setasign/fpdf/fpdf.php');

class ResolutionPDF extends FPDF {
    // Header
    function Header() {
        // Quezon City Logo
        $this->Image('qc_logo.png', 10, 8, 25);
        
        // Title
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'QUEZON CITY GOVERNMENT', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'SANGGUNIANG PANLUNGSOD', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'RESOLUTION NO. ______', 0, 1, 'C');
        
        $this->Ln(5);
    }
    
    // Footer
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
    }
    
    // Resolution Content
    function AddResolutionContent($title, $sponsors, $whereases, $resolutions) {
        // Resolution Title
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, $title, 0, 1, 'C');
        $this->Ln(5);
        
        // Sponsored by
        $this->SetFont('Arial', 'I', 12);
        $this->Cell(0, 10, 'Sponsored by: ' . $sponsors, 0, 1, 'C');
        $this->Ln(10);
        
        // Whereas clauses
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'WHEREAS,', 0, 1);
        $this->SetFont('Arial', '', 12);
        
        foreach ($whereases as $i => $whereas) {
            if ($i > 0) {
                $this->SetFont('Arial', 'B', 12);
                $this->Cell(0, 10, 'WHEREAS,', 0, 1);
                $this->SetFont('Arial', '', 12);
            }
            $this->MultiCell(0, 8, $whereas);
            $this->Ln(5);
        }
        
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'NOW, THEREFORE,', 0, 1);
        $this->MultiCell(0, 8, 'Be it RESOLVED, as it is hereby RESOLVED by the Sangguniang Panlungsod of Quezon City in session assembled:');
        $this->Ln(10);
        
        // Resolving clauses
        foreach ($resolutions as $i => $resolution) {
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 10, 'SECTION ' . ($i + 1) . '.', 0, 1);
            $this->SetFont('Arial', '', 12);
            $this->MultiCell(0, 8, $resolution);
            $this->Ln(5);
        }
        
        // Approval Section
        $this->Ln(20);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'RESOLVED this ______ day of ____________, 20___.', 0, 1, 'C');
        $this->Ln(15);
        
        $this->Cell(0, 10, '_________________________', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'City Vice Mayor & Presiding Officer', 0, 1, 'C');
        $this->Ln(10);
        
        $this->Cell(0, 10, 'ATTESTED:', 0, 1, 'C');
        $this->Ln(5);
        $this->Cell(0, 10, '_________________________', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Secretary to the Sanggunian', 0, 1, 'C');
    }
}

// Usage example
$pdf = new ResolutionPDF();
$pdf->AliasNbPages();
$pdf->AddPage();

$title = 'A RESOLUTION APPROPRIATING FUNDS FOR THE CITY\'S ENVIRONMENTAL PROGRAM';
$sponsors = 'Councilor Maria Santos';
$whereases = [
    'The City Government of Quezon City recognizes the importance of environmental protection;',
    'There is an urgent need to address environmental concerns within the city;',
    'Funds are available for environmental programs in the current fiscal year.'
];

$resolutions = [
    'The amount of Five Million Pesos (Php 5,000,000.00) is hereby appropriated from the Environmental Fund;',
    'The City Treasurer is authorized to release the said amount for environmental projects;',
    'The City Environment and Natural Resources Office shall implement the program.'
];

$pdf->AddResolutionContent($title, $sponsors, $whereases, $resolutions);
$pdf->Output('I', 'Quezon_City_Resolution_Template.pdf');
?>