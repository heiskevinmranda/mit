<?php
require_once __DIR__ . '/../vendor/autoload.php';  // Autoloader for TCPDF
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';  // Include auth functions to get current user info

class TicketReportPDFGenerator
{
    private $pdf;
    private $report_data;
    private $filters;
    private $client_name;
    private $custom_suggestions;
    private $detailed_suggestion_title;
    private $detailed_suggestion_description;
    private $additional_comments;
    private $before_image_path;
    private $after_image_path;

    public function __construct($report_data, $filters)
    {
        $this->report_data = $report_data;
        $this->filters = $filters;
        $this->client_name = $this->getClientNameById($filters['client_id'] ?? null) ?? 'All Clients';
        $this->custom_suggestions = $filters['custom_suggestions'] ?? '';
        $this->detailed_suggestion_title = $filters['detailed_suggestion_title'] ?? '';
        $this->detailed_suggestion_description = $filters['detailed_suggestion_description'] ?? '';
        $this->additional_comments = $filters['additional_comments'] ?? '';
        $this->before_image_path = $filters['before_image_path'] ?? '';
        $this->after_image_path = $filters['after_image_path'] ?? '';

        // Determine report title based on dates
        $startDate = new DateTime($this->filters['start_date'] ?? date('Y-m-d'));
        $endDate = new DateTime($this->filters['end_date'] ?? date('Y-m-d'));
        if ($startDate->format('Y-m') === $endDate->format('Y-m')) {
            $report_title = 'AMC ' . $startDate->format('F Y') . ' Report';
        } elseif ($startDate->format('Y') === $endDate->format('Y')) {
            $report_title = 'AMC ' . $startDate->format('F') . ' - ' . $endDate->format('F Y') . ' Report';
        } else {
            $report_title = 'AMC ' . $startDate->format('M Y') . ' - ' . $endDate->format('M Y') . ' Report';
        }

        // Initialize TCPDF with standard A4 landscape format for better report layout
        $this->pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $this->pdf->SetCreator(PDF_CREATOR);
        $this->pdf->SetTitle($report_title . ' - ' . $this->client_name);
        $this->pdf->SetAuthor('Flashnet MSP Application');

        // Remove default header/footer
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);

        // Set margins
        $this->pdf->SetMargins(20, 20, 20);
        $this->pdf->SetAutoPageBreak(TRUE, 20);

        // Add a page
        $this->pdf->AddPage();
    }

    public function generate()
    {
        // 1. Cover / Header Section
        $this->addHeaderSection();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 2. Services Introduction
        $this->addServicesIntroduction();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 3. Audit Numbers / KPI Metrics
        $this->addAuditNumbers();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 4. Our Agenda
        $this->addOurAgenda();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 5. Activities Calendar
        $this->addActivitiesCalendar();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 6. Activities Tickets (Detailed Tickets Table)
        $this->addActivitiesTickets();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 7. Suggestion Ideas / Recommendations
        $this->addSuggestionIdeas();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 8. Detailed Suggestion Example (e.g., Server Rack Cleanup with images)
        $this->addDetailedSuggestion();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 9. Thank You / Footer Section
        $this->addThankYouSection();

        return $this->pdf;
    }

    private function addHeaderSection()
    {
        $pageWidth  = $this->pdf->getPageWidth();
        $pageHeight = $this->pdf->getPageHeight();
        $margin     = 20;
        $contentWidth = $pageWidth - ($margin * 2);

        /* =========================
           FLASHNET LOGO (POSITIONED CLEARLY ABOVE TEXT)
        ========================== */
        $logoPath = __DIR__ . '/../assets/flashnet_logo.png';  // Assume renamed to match template
        if (file_exists($logoPath)) {
            $logoWidth  = 80;
            $logoHeight = 40;

            $logoX = ($pageWidth - $logoWidth) / 2; // Center the logo
            $logoY = 20; // Position at the top

            $this->pdf->Image(
                $logoPath,
                $logoX,
                $logoY,
                $logoWidth,
                $logoHeight,
                'PNG'
            );
        } else {
            // If logo file doesn't exist, create a placeholder with text
            $this->pdf->SetTextColor(255, 102, 0); // Orange
            $this->pdf->SetFont('helvetica', 'B', 24);
            $logoX = ($pageWidth - 100) / 2; // Center the text
            $this->pdf->SetXY($logoX, 30);
            $this->pdf->Cell(100, 10, 'FLASHNET', 0, 1, 'C');
            $this->pdf->SetTextColor(0, 0, 0); // Black
            $this->pdf->SetFont('helvetica', '', 12);
            $this->pdf->Cell(100, 5, 'Managed IT Solutions', 0, 1, 'C');
        }

        /* =========================
           MAIN TITLE (MOVED DOWN TO APPEAR BELOW LOGO)
        ========================== */
        $this->pdf->SetTextColor(0, 0, 0); // Dark gray
        $this->pdf->SetFont('helvetica', 'B', 30);
        $this->pdf->SetXY($margin, 80); // Moved down from 60 to 80 to accommodate the larger logo
        $this->pdf->Cell(
            $contentWidth,
            12,
            'MANAGED IT',
            0,
            1,
            'C'
        );
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->Cell(
            $contentWidth,
            12,
            'SERVICES',
            0,
            1,
            'C'
        );

        /* =========================
           TAGLINE
        ========================== */
        $this->pdf->SetFont('helvetica', '', 18);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Cell(
            $contentWidth,
            10,
            "Focus on your business. We'll do the rest",
            0,
            1,
            'C'
        );

        /* =========================
           CLIENT LOGO AND REPORT TITLE
        ========================== */
        // Get client logo from database
        $clientLogoPath = $this->getClientLogoPath();
        if ($clientLogoPath && file_exists($clientLogoPath)) {
            $clientLogoWidth  = 40;
            $clientLogoHeight = 40;
            $clientLogoX = ($pageWidth - $clientLogoWidth) / 2 - 30; // Slightly left
            $clientLogoY = $this->pdf->GetY() + 20;

            $this->pdf->Image(
                $clientLogoPath,
                $clientLogoX,
                $clientLogoY,
                $clientLogoWidth,
                $clientLogoHeight,
                'PNG'
            );
        }

        $this->pdf->SetXY(($pageWidth / 2) + 10, $this->pdf->GetY() + 30);
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->SetTextColor(0, 128, 0); // Green for report title
        $startDate = new DateTime($this->filters['start_date'] ?? date('Y-m-d'));
        $reportTitle = 'AMC ' . $startDate->format('F Y') . ' Report.';
        $this->pdf->Cell(
            $contentWidth / 2,
            12,
            $reportTitle,
            0,
            1,
            'L'
        );

        /* =========================
           WEBSITE
        ========================== */
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Cell(
            $contentWidth,
            10,
            'www.flashnet.co.tz',
            0,
            1,
            'C'
        );
    }

    private function addServicesIntroduction()
    {
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->Cell(0, 10, 'Flashnet - The best Managed IT service provider in Tanzania', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0); // Black
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->MultiCell(0, 5, 'As a leading Managed IT Service Provider in Tanzania, Flashnet offers a wide range of comprehensive IT services to meet your business needs. Our services include Remote IT Support Services, Computer and Network Support, Server Support Services, Server Monitoring Services, Network and Server Administration, Backup and Disaster Recovery Services, and Managed Wi-Fi Service.', 0, 'L');

        // Service boxes
        $this->pdf->Ln(10);
        $this->pdf->SetFillColor(255, 102, 0); // Orange
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(10, 10, '01', 1, 0, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 10, ' Remote IT Support Services', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, 'Our Remote IT Support Services provide quick and efficient troubleshooting and resolution of computer system issues, no matter where you are located in Tanzania.', 0, 'L');

        $this->pdf->Ln(5);
        $this->pdf->SetFillColor(255, 102, 0);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(10, 10, '02', 1, 0, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 10, ' Server Support Services', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, 'With our Server Support Services, we ensure your servers run smoothly and minimize downtime, ensuring your business operations are always running.', 0, 'L');

        // Add more similarly for 03 and 04
        $this->pdf->Ln(5);
        $this->pdf->SetFillColor(255, 102, 0);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(10, 10, '03', 1, 0, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 10, ' Computer and Network Support', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, 'Our experienced IT professionals provide comprehensive Computer and Network Support to ensure your systems run efficiently and effectively.', 0, 'L');

        $this->pdf->Ln(5);
        $this->pdf->SetFillColor(255, 102, 0);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(10, 10, '04', 1, 0, 'C', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 10, ' Server Monitoring Services', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, 'Our 24/7 Server Monitoring Services proactively detect and address issues before they become major problems, ensuring your systems operate at peak performance levels.', 0, 'L');
    }

    private function addAuditNumbers()
    {
        $this->pdf->SetTextColor(0, 0, 128); // Navy blue
        $this->pdf->SetFont('helvetica', 'B', 18);
        $this->pdf->Cell(0, 10, 'AUDIT NUMBERS', 0, 1, 'C');

        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->Cell(0, 5, 'As per ' . date('d/m/Y'), 0, 1, 'C');

        // Icons and metrics grid (simulate with text, assume icons paths if available)
        // For simplicity, use text labels
        $stats = $this->report_data['stats'] ?? [];
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(50, 10, 'Antivirus Status', 0, 0, 'C');
        $this->pdf->Cell(50, 10, 'Device Performance', 0, 0, 'C');
        $this->pdf->Cell(50, 10, 'Microsoft Office License', 0, 0, 'C');
        $this->pdf->Cell(50, 10, 'Operating System License', 0, 1, 'C');

        $this->pdf->SetFillColor(255, 102, 0); // Orange
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell(50, 10, ($stats['active_antivirus'] ?? 50) . ' Active', 0, 0, 'C', true);
        $this->pdf->Cell(50, 10, ($stats['good_performance'] ?? 30) . ' Good', 0, 0, 'C', true);
        $this->pdf->Cell(50, 10, ($stats['active_licenses'] ?? 30) . ' Active', 0, 0, 'C', true);
        $this->pdf->Cell(50, 10, ($stats['pro_os'] ?? 39) . ' Windows Pro', 0, 1, 'C', true);

        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell(50, 10, ($stats['not_active_antivirus'] ?? 5) . ' Not Active', 0, 0, 'C', true);
        $this->pdf->Cell(50, 10, ($stats['poor_performance'] ?? 0) . ' Poor', 0, 0, 'C', true);
        $this->pdf->Cell(50, 10, ($stats['not_active_licenses'] ?? 1) . ' Not Active', 0, 0, 'C', true);
        $this->pdf->Cell(50, 10, ($stats['home_os'] ?? 5) . ' Win Home', 0, 1, 'C', true);
    }

    private function addOurAgenda()
    {
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->Cell(0, 10, 'Our Agenda', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->Cell(0, 5, 'We are committed to providing the best services to grow your business.', 0, 1, 'L');

        // Agenda boxes
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(60, 10, 'Activities Calendar', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(60, 5, "It's about visual representation of our commitments, Tasks and services provided to customer.", 0, 'L');

        $this->pdf->SetY($this->pdf->GetY() - 30); // Move up for next column
        $this->pdf->SetX(100);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(60, 10, 'Statistics', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(60, 5, "It's about tracking progress, measure performance, analyze problems and prioritize.", 0, 'L');

        $this->pdf->SetY($this->pdf->GetY() - 30);
        $this->pdf->SetX(160);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(60, 10, 'Tickets', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(60, 5, "It's combination of practices, strategies and technologies to manage and analyze data through customer lifecycle.", 0, 'L');

        $this->pdf->SetY($this->pdf->GetY() - 30);
        $this->pdf->SetX(220);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(60, 10, 'Support', 0, 1, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(60, 5, "It's ultimately about making sure customers are successful in solving whatever issues they came to your business to help solve.", 0, 'L');
    }

    private function addActivitiesCalendar()
    {
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->Cell(0, 10, 'Activities Calendar', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->Cell(0, 5, "It's about visual representation of our commitments, Tasks and services provided to customer.", 0, 1, 'L');

        // Generate calendar table based on start_date month
        // For simplicity, simulate a monthly calendar with dummy data or count tickets per day
        $startDate = new DateTime($this->filters['start_date'] ?? date('Y-m-01'));
        $month = $startDate->format('F');
        $year = $startDate->format('Y');
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->Cell(0, 5, 'FLASHNET AMC CLIENT RECORD - ' . $this->client_name . ' - Enter Year: ' . $year, 0, 1, 'L');

        // Calendar header (days)
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        foreach ($days as $day) {
            $this->pdf->Cell(20, 5, $day, 1, 0, 'C');
        }
        $this->pdf->Ln();

        // Fill calendar rows (simplified, use actual date calculations in production)
        // Group tickets by date for counts
        $ticketCounts = [];
        foreach ($this->report_data['recent_tickets'] ?? [] as $ticket) {
            $date = date('Y-m-d', strtotime($ticket['created_at']));
            $ticketCounts[$date] = ($ticketCounts[$date] ?? 0) + 1;
        }

        // Assume a 5-week calendar for the month
        for ($week = 0; $week < 5; $week++) {
            for ($day = 1; $day <= 7; $day++) {
                $dayNum = ($week * 7) + $day;
                if ($dayNum > 31) break; // Rough month end
                $dateKey = $year . '-' . $startDate->format('m') . '-' . str_pad($dayNum, 2, '0', STR_PAD_LEFT);
                $count = $ticketCounts[$dateKey] ?? 0;
                $this->pdf->Cell(20, 5, $dayNum . ' (' . $count . ')', 1, 0, 'C');
            }
            $this->pdf->Ln();
        }

        // Key statistics at bottom (colored boxes)
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->Cell(0, 5, 'KEY STATISTICS', 0, 1, 'L');
        $keys = ['Total Tickets' => $this->report_data['stats']['total_tickets'] ?? 2, 'Auditing' => 0, 'Site Survey' => 0, /* add more */];
        foreach ($keys as $label => $value) {
            $this->pdf->SetFillColor(rand(0,255), rand(0,255), rand(0,255)); // Random colors for demo
            $this->pdf->Cell(30, 10, $label . ': ' . $value, 1, 0, 'C', true);
        }
    }

    private function addActivitiesTickets()
    {
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->Cell(0, 10, 'Activities Tickets', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->Cell(0, 5, "It's combination of practices, strategies and technologies to manage and analyze data through customer lifecycle.", 0, 1, 'L');

        // Tickets table
        $this->pdf->Ln(10);
        $this->pdf->SetFillColor(255, 102, 0);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->Cell(30, 10, 'Date', 1, 0, 'C', true);
        $this->pdf->Cell(50, 10, 'Client Name', 1, 0, 'C', true);
        $this->pdf->Cell(60, 10, 'Subject', 1, 0, 'C', true);
        $this->pdf->Cell(40, 10, 'Category', 1, 0, 'C', true);
        $this->pdf->Cell(30, 10, 'Status', 1, 0, 'C', true);
        $this->pdf->Cell(40, 10, 'Assigned to', 1, 1, 'C', true);

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 9);
        $tickets = $this->report_data['recent_tickets'] ?? [];
        $fill = false;
        foreach ($tickets as $ticket) {
            $this->pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $this->pdf->Cell(30, 10, date('d/m/Y', strtotime($ticket['created_at'])), 1, 0, 'C', true);
            $this->pdf->Cell(50, 10, $ticket['company_name'] ?? $this->client_name, 1, 0, 'L', true);
            $this->pdf->Cell(60, 10, substr($ticket['title'] ?? 'No Subject', 0, 30), 1, 0, 'L', true);
            $this->pdf->Cell(40, 10, $ticket['category'] ?? 'System', 1, 0, 'C', true);
            $this->pdf->Cell(30, 10, $ticket['status'], 1, 0, 'C', true);
            $this->pdf->Cell(40, 10, $ticket['assigned_to'] ?? 'Unassigned', 1, 1, 'C', true);
            $fill = !$fill;
        }
    }

    private function addSuggestionIdeas()
    {
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->Cell(0, 10, 'Suggestion Ideas', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->Cell(0, 5, 'Plans brought forward for consideration', 0, 1, 'L');

        // List suggestions
        if (!empty($this->custom_suggestions)) {
            // Use custom suggestions provided by user
            $this->pdf->Ln(10);
            $this->pdf->SetFont('helvetica', 'B', 12);
            $this->pdf->MultiCell(0, 5, $this->custom_suggestions, 0, 'L');
        } else {
            // If no custom suggestions provided, indicate that
            $this->pdf->Ln(10);
            $this->pdf->SetFont('helvetica', 'I', 12);
            $this->pdf->SetTextColor(128, 128, 128); // Gray color
            $this->pdf->Cell(0, 5, 'No suggestions provided', 0, 1, 'L');
            $this->pdf->SetTextColor(0, 0, 0); // Reset to black
            $this->pdf->SetFont('helvetica', '', 12);
        }

    }

    private function addDetailedSuggestion()
    {
        $this->pdf->SetTextColor(0, 0, 0);
        
        // Only add content if user has provided custom data
        if (!empty($this->detailed_suggestion_title) || !empty($this->detailed_suggestion_description) || 
            !empty($this->before_image_path) || !empty($this->after_image_path) || !empty($this->additional_comments)) {
            
            if (!empty($this->detailed_suggestion_title)) {
                $this->pdf->SetFont('helvetica', 'B', 16);
                $this->pdf->Cell(0, 10, $this->detailed_suggestion_title, 0, 1, 'L');
            } else {
                // Use a generic title if only description is provided
                if (!empty($this->detailed_suggestion_description)) {
                    $this->pdf->SetFont('helvetica', 'B', 16);
                    $this->pdf->Cell(0, 10, 'Detailed Suggestion', 0, 1, 'L');
                }
            }
    
            if (!empty($this->detailed_suggestion_description)) {
                $this->pdf->SetFont('helvetica', '', 12);
                $this->pdf->MultiCell(0, 5, $this->detailed_suggestion_description, 0, 'L');
            }
    
            // Add descriptions and image placeholders (use user uploaded images if available)
            $this->pdf->Ln(5);
            
            if (!empty($this->before_image_path)) {
                $this->pdf->Cell(0, 5, 'Current State', 0, 1, 'L');
                $this->pdf->MultiCell(0, 5, 'Current situation showing the issue that needs to be addressed.', 0, 'L');
                
                // Add uploaded before image
                $imagePath = __DIR__ . $this->before_image_path;
                if (file_exists($imagePath)) {
                    $this->pdf->Image($imagePath, 20, $this->pdf->GetY(), 60, 40);
                }
                
                $this->pdf->Ln(50); // Space for image
            }
            
            if (!empty($this->after_image_path)) {
                $this->pdf->Cell(0, 5, 'Desired State', 0, 1, 'L');
                $this->pdf->MultiCell(0, 5, 'The recommended solution or improvement.', 0, 'L');
                
                // Add uploaded after image
                $imagePath = __DIR__ . $this->after_image_path;
                if (file_exists($imagePath)) {
                    $this->pdf->Image($imagePath, 20, $this->pdf->GetY(), 60, 40);
                }
                
                $this->pdf->Ln(50); // Space for image
            }
            
            // Add additional comments if provided
            if (!empty($this->additional_comments)) {
                $this->pdf->Ln(10);
                $this->pdf->SetFont('helvetica', 'B', 14);
                $this->pdf->Cell(0, 10, 'Additional Notes', 0, 1, 'L');
                $this->pdf->SetFont('helvetica', '', 12);
                $this->pdf->MultiCell(0, 5, $this->additional_comments, 0, 'L');
            }
        } else {
            // If no custom data provided, indicate that
            $this->pdf->SetFont('helvetica', 'B', 16);
            $this->pdf->Cell(0, 10, 'Detailed Suggestion', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', 'I', 12);
            $this->pdf->SetTextColor(128, 128, 128); // Gray color
            $this->pdf->Cell(0, 5, 'No detailed suggestions provided', 0, 1, 'L');
            $this->pdf->SetTextColor(0, 0, 0); // Reset to black
            $this->pdf->SetFont('helvetica', '', 12);
        }
    }

    private function addThankYouSection()
    {
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', 'B', 30);
        $this->pdf->Cell(0, 20, 'THANK YOU', 0, 1, 'C');

        $this->pdf->SetFont('helvetica', '', 14);
        $this->pdf->MultiCell(0, 5, 'We guarantee that our IT Services can transform seamlessly to excel and ace the new world of IT demands.', 0, 'C');

        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->MultiCell(0, 5, 'Our inception in 2011 was a result of our desire to provide superior, reliable and effective Information Technology (IT) solutions across Tanzania. Our vision was to be a preferred and a wholesome IT solutions partner to SMB, SME and Enterprises.', 0, 'C');

        $this->pdf->Ln(10);
        $this->pdf->Cell(0, 5, '1st Floor, PPF Towers Ohio Street, Dar-es-salaam Tanzania', 0, 1, 'C');
        $this->pdf->Cell(0, 5, 'info@flashnet.co.tz', 0, 1, 'C');
        $this->pdf->Cell(0, 5, '+255 22 211 3687', 0, 1, 'C');
        $this->pdf->Cell(0, 5, '+255 777 988 883', 0, 1, 'C');
    }

    private function calculateHealthScore()
    {
        // Keep if needed, but not used in new structure
        $stats = $this->report_data['stats'] ?? [];
        $total_tickets = $stats['total_tickets'] ?? 0;

        if ($total_tickets <= 0) {
            return 100;
        }

        $resolved_rate = ($stats['resolved_tickets'] + $stats['closed_tickets']) / $total_tickets;
        $critical_rate = $stats['critical_tickets'] / $total_tickets;
        $open_rate = $stats['open_tickets'] / $total_tickets;

        $health_score = round((($resolved_rate * 100) - ($critical_rate * 30) - ($open_rate * 40)) * 1.5, 0);
        $health_score = max(0, min(100, $health_score));

        return $health_score;
    }

    private function getClientNameById($client_id)
    {
        if (!$client_id) return null;
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT company_name FROM clients WHERE id = ?");
            $stmt->execute([$client_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            return $client['company_name'] ?? 'Unknown Client';
        } catch (Exception $e) {
            return 'Unknown Client';
        }
    }

    private function getClientLogoPath()
    {
        if (!$this->filters['client_id']) return null;
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT logo_path FROM clients WHERE id = ?");
            $stmt->execute([$this->filters['client_id']]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($client && !empty($client['logo_path'])) {
                return __DIR__ . '/../' . $client['logo_path'];
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}