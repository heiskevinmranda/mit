<?php
// Prevent any output from this file
ob_start();
require_once __DIR__ . '/../vendor/autoload.php';  // Autoloader for TCPDF
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';  // Include auth functions to get current user info

class TicketReportPDFGenerator
{
    private $pdf;
    private $report_data;
    private $filters;
    private $client_name;
    private $year;
    private $category_colors;
    private $custom_suggestions;
    private $detailed_suggestion_title;
    private $detailed_suggestion_description;
    private $additional_comments;
    private $before_image_path;
    private $after_image_path;

    public function __construct($report_data, $filters)
    {
        error_log("TicketReportPDFGenerator: Constructor called with data keys: " . json_encode(array_keys($report_data ?? [])) .
            " and filter keys: " . json_encode(array_keys($filters ?? [])));

        $this->report_data = $report_data;
        $this->filters = $filters;
        $this->client_name = $this->getClientNameById($filters['client_id'] ?? null) ?? 'All Clients';
        $this->custom_suggestions = $filters['custom_suggestions'] ?? '';
        $this->detailed_suggestion_title = $filters['detailed_suggestion_title'] ?? '';
        $this->detailed_suggestion_description = $filters['detailed_suggestion_description'] ?? '';
        $this->additional_comments = $filters['additional_comments'] ?? '';
        $this->before_image_path = $filters['before_image_path'] ?? '';
        $this->after_image_path = $filters['after_image_path'] ?? '';

        // Extract dates from filters
        $startDate = new DateTime($this->filters['start_date'] ?? date('Y-m-d'));
        $endDate = new DateTime($this->filters['end_date'] ?? date('Y-m-d'));

        // Extract year from start date for calendar
        $this->year = $startDate->format('Y');
        if ($startDate->format('Y-m') === $endDate->format('Y-m')) {
            $report_title = 'AMC ' . $startDate->format('F Y') . ' Report';
        } elseif ($startDate->format('Y') === $endDate->format('Y')) {
            $report_title = 'AMC ' . $startDate->format('F') . ' - ' . $endDate->format('F Y') . ' Report';
        } else {
            $report_title = 'AMC ' . $startDate->format('M Y') . ' - ' . $endDate->format('M Y') . ' Report';
        }

        error_log("TicketReportPDFGenerator: Initializing TCPDF with title: $report_title and client: {$this->client_name}");

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

        // Initialize category colors for calendar and statistics
        $this->initializeCategoryColors();

        error_log("TicketReportPDFGenerator: Constructor completed successfully");
    }

    private function initializeCategoryColors()
    {
        // Initialize category colors for use in calendar and statistics
        $this->category_colors = [
            'General' => [[255, 165, 0], [0, 0, 0]], // Orange
            'Hardware' => [[144, 238, 144], [0, 0, 0]], // Light green
            'Network' => [[173, 216, 230], [0, 0, 0]], // Light blue
            'Server' => [[255, 102, 0], [0, 0, 0]], // Orange-red
            'Firewall' => [[255, 0, 0], [255, 255, 255]], // Red
            'Email' => [[0, 0, 139], [255, 255, 255]], // Dark blue
            'Security' => [[75, 0, 130], [255, 255, 255]], // Indigo
            'CCTV' => [[128, 0, 128], [255, 255, 255]], // Purple
            'Biometric' => [[255, 20, 147], [0, 0, 0]], // Deep pink
            'Software' => [[60, 179, 113], [0, 0, 0]], // Medium sea green
        ];
    }

    public function generate()
    {
        error_log("TicketReportPDFGenerator: Starting PDF generation");

        try {
            // 1. Cover / Header Section
            error_log("TicketReportPDFGenerator: Adding header section");
            $this->addHeaderSection();

            // Add page break to separate sections
            error_log("TicketReportPDFGenerator: Adding page break after header");
            $this->pdf->AddPage();

            // 2. Services Introduction
            error_log("TicketReportPDFGenerator: Adding services introduction");
            $this->addServicesIntroduction();

            // Add page break to separate sections
            error_log("TicketReportPDFGenerator: Adding page break after services intro");
            $this->pdf->AddPage();

            // Audit Numbers section removed as per user request

            // 4. Our Agenda
            error_log("TicketReportPDFGenerator: Adding our agenda");
            $this->addOurAgenda();

            // Add page break to separate sections
            error_log("TicketReportPDFGenerator: Adding page break after agenda");
            $this->pdf->AddPage();

            // 5. Activities Calendar
            error_log("TicketReportPDFGenerator: Adding activities calendar");
            $this->addActivitiesCalendar();

            // Add page break to separate sections
            error_log("TicketReportPDFGenerator: Adding page break after calendar");
            $this->pdf->AddPage();

            // 6. Activities Tickets (Detailed Tickets Table)
            error_log("TicketReportPDFGenerator: Adding activities tickets");
            $this->addActivitiesTickets();

            // Add page break to separate sections
            error_log("TicketReportPDFGenerator: Adding page break after tickets");
            $this->pdf->AddPage();

            // 7. Suggestion Ideas / Recommendations
            error_log("TicketReportPDFGenerator: Adding suggestion ideas");
            $this->addSuggestionIdeas();

            // Add page break to separate sections
            error_log("TicketReportPDFGenerator: Adding page break after suggestions");
            $this->pdf->AddPage();

            // 8. Detailed Suggestion Example (e.g., Server Rack Cleanup with images)
            error_log("TicketReportPDFGenerator: Adding detailed suggestion");
            $this->addDetailedSuggestion();

            // Add page break to separate sections
            error_log("TicketReportPDFGenerator: Adding page break after detailed suggestion");
            $this->pdf->AddPage();

            // 9. Thank You / Footer Section
            error_log("TicketReportPDFGenerator: Adding thank you section");
            $this->addThankYouSection();

            error_log("TicketReportPDFGenerator: PDF generation completed successfully");
        } catch (Exception $e) {
            error_log("TicketReportPDFGenerator ERROR: " . $e->getMessage() .
                " | File: " . $e->getFile() .
                " | Line: " . $e->getLine() .
                " | Trace: " . $e->getTraceAsString());
            throw $e; // Re-throw to be caught by caller
        }

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
        $logoPath = __DIR__ . '/../assets/flashnet_logo.png';  // Assume path
        if (file_exists($logoPath)) {
            // Get image dimensions to preserve aspect ratio
            $imgSize = getimagesize($logoPath);
            if ($imgSize !== false) {
                $imgWidth = $imgSize[0];
                $imgHeight = $imgSize[1];

                // Calculate dimensions to fit within a max size while preserving aspect ratio
                $maxLogoWidth = 80;
                $maxLogoHeight = 40;

                // Calculate scaling factor to fit within max dimensions
                $widthRatio = $maxLogoWidth / $imgWidth;
                $heightRatio = $maxLogoHeight / $imgHeight;
                $scaleRatio = min($widthRatio, $heightRatio); // Use the smaller ratio to fit

                $logoWidth = $imgWidth * $scaleRatio;
                $logoHeight = $imgHeight * $scaleRatio;

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
                // Fallback to original sizing if getimagesize fails
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
            }
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
        $this->pdf->SetXY($margin, 65); // Reduced from 80 to 65 to bring title closer to logo
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
            8,
            "Focus on your business. We'll do the rest",
            0,
            1,
            'C'
        );

        /* =========================
           CLIENT LOGO AND REPORT TITLE (GROUPED AND CENTERED)
        ========================== */
        // Get client logo from database
        $clientLogoPath = $this->getClientLogoPath();

        // Calculate positions for centered grouped content
        $groupStartY = $this->pdf->GetY() + 15; // Reduced from 30 to 15 to bring it closer to tagline

        if ($clientLogoPath && file_exists($clientLogoPath)) {
            // Get image dimensions to preserve aspect ratio
            $imgSize = getimagesize($clientLogoPath);
            if ($imgSize !== false) {
                $imgWidth = $imgSize[0];
                $imgHeight = $imgSize[1];

                // Calculate dimensions to fit within a max size while preserving aspect ratio
                $maxClientLogoWidth = 40;
                $maxClientLogoHeight = 40;

                // Calculate scaling factor to fit within max dimensions
                $widthRatio = $maxClientLogoWidth / $imgWidth;
                $heightRatio = $maxClientLogoHeight / $imgHeight;
                $scaleRatio = min($widthRatio, $heightRatio); // Use the smaller ratio to fit

                $clientLogoWidth = $imgWidth * $scaleRatio;
                $clientLogoHeight = $imgHeight * $scaleRatio;
            } else {
                // Fallback to original sizing if getimagesize fails
                $clientLogoWidth  = 40;
                $clientLogoHeight = 40;
            }

            // Calculate total width needed for logo + title
            $startDate = new DateTime($this->filters['start_date'] ?? date('Y-m-d'));
            $reportTitle = 'AMC ' . $startDate->format('F Y') . ' Report.';

            // Get text width
            $this->pdf->SetFont('helvetica', 'B', 24);
            $textWidth = $this->pdf->GetStringWidth($reportTitle);

            // Total group width
            $totalGroupWidth = $clientLogoWidth + 10 + $textWidth; // 10px spacing

            // Calculate starting X position to center the whole group
            $groupStartX = ($pageWidth - $totalGroupWidth) / 2;

            // Draw client logo
            $clientLogoX = $groupStartX;
            $clientLogoY = $groupStartY;

            $this->pdf->Image(
                $clientLogoPath,
                $clientLogoX,
                $clientLogoY,
                $clientLogoWidth,
                $clientLogoHeight,
                'PNG'
            );

            // Draw report title next to the logo
            $titleX = $clientLogoX + $clientLogoWidth + 10; // 10px spacing
            $this->pdf->SetXY($titleX, $clientLogoY + ($clientLogoHeight - 8) / 2); // Vertically center align
            $this->pdf->SetFont('helvetica', 'B', 24);
            $this->pdf->SetTextColor(0, 128, 0); // Green for report title
            $this->pdf->Cell(
                $textWidth,
                12,
                $reportTitle,
                0,
                0,
                'L'
            );
        } else {
            // If no client logo, just center the title
            $this->pdf->SetXY(($pageWidth / 2) - 50, $groupStartY + 10);
            $this->pdf->SetFont('helvetica', 'B', 24);
            $this->pdf->SetTextColor(0, 128, 0); // Green for report title
            $startDate = new DateTime($this->filters['start_date'] ?? date('Y-m-d'));
            $reportTitle = 'AMC ' . $startDate->format('F Y') . ' Report.';
            $this->pdf->Cell(
                100,
                12,
                $reportTitle,
                0,
                0,
                'C'
            );
        }

        /* =========================
           WEBSITE
        ========================== */
        $this->pdf->Ln(15); // Reduced spacing to keep website on cover page
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
        $pdf = $this->pdf;

        // Page & layout settings
        $pageWidth   = $pdf->getPageWidth();
        $leftMargin  = 15;
        $rightMargin = 15;
        $colGap      = 10;
        $startY      = 25;

        // Column widths (≈35% / 65%)
        $leftColWidth  = 70;
        $rightColWidth = $pageWidth - $leftMargin - $rightMargin - $leftColWidth - $colGap;

        // Track Y positions separately
        $leftY  = $startY + 40;
        $rightY = $startY;

        /* =====================================================
     * LEFT COLUMN – Services overview
     * ===================================================== */
        $leftServices = [
            [
                'title' => 'Cloud & Cyber Security',
                'desc'  => 'Benefit from cloud computing power for your applications and data.'
            ],
            [
                'title' => 'Data Center Solutions in Tanzania',
                'desc'  => 'Migrate now to collaborate in real-time, reduce on-premise hardware and operational costs.'
            ],
            [
                'title' => 'Hosting',
                'desc'  => 'Secure and dedicated hosting solutions with expert technical support.'
            ],
        ];

        foreach ($leftServices as $item) {

            // Icon (orange circle placeholder)
            $pdf->SetFillColor(255, 102, 0);
            $pdf->Circle($leftMargin + 6, $leftY + 6, 6, 0, 360, 'F');

            // Title
            $pdf->SetXY($leftMargin + 18, $leftY);
            $pdf->SetFont('helvetica', 'B', 13);
            $pdf->SetTextColor(255, 102, 0);
            $pdf->MultiCell($leftColWidth - 18, 6, $item['title'], 0, 'L');

            // Description
            $leftY = $pdf->GetY();
            $pdf->SetX($leftMargin + 18);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell($leftColWidth - 18, 5, $item['desc'], 0, 'L');

            $leftY = $pdf->GetY() + 10;
        }

        /* =====================================================
     * RIGHT COLUMN – Main content
     * ===================================================== */
        $rightX = $leftMargin + $leftColWidth + $colGap;

        // Big title
        $pdf->SetXY($rightX, $rightY);
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->SetTextColor(255, 102, 0);
        $pdf->MultiCell($rightColWidth, 12, 'Flashnet - The Best Managed IT Service Provider in Tanzania', 0, 'L');

        // Intro text
        $rightY = $pdf->GetY() + 6;
        $pdf->SetXY($rightX, $rightY);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(
            $rightColWidth,
            6,
            'As a leading Managed IT Service Provider in Tanzania, Flashnet offers comprehensive IT services including Remote IT Support, Network & Server Support, Server Monitoring, Backup & Disaster Recovery, and Managed Wi-Fi Services.',
            0,
            'L'
        );

        $rightY = $pdf->GetY() + 10;

        // Numbered services
        $services = [
            ['01', 'Remote IT Support Services', 'Fast and efficient troubleshooting and resolution of IT issues across Tanzania.'],
            ['02', 'Server Support Services', 'Reliable server maintenance ensuring minimal downtime and business continuity.'],
            ['03', 'Computer & Network Support', 'End-to-end support to keep your systems secure and optimized.'],
            ['04', 'Server Monitoring Services', '24/7 proactive monitoring to detect and resolve issues before impact.'],
        ];

        foreach ($services as $s) {

            // Number box
            $pdf->SetXY($rightX, $rightY);
            $pdf->SetFillColor(255, 102, 0);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(12, 12, $s[0], 0, 0, 'C', true);

            // Title
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', 'B', 13);
            $pdf->SetXY($rightX + 16, $rightY);
            $pdf->MultiCell($rightColWidth - 16, 12, $s[1], 0, 'L');

            // Description
            $rightY = max($pdf->GetY(), $rightY + 12);
            $pdf->SetXY($rightX + 16, $rightY);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell($rightColWidth - 16, 5.2, $s[2], 0, 'L');

            $rightY = $pdf->GetY() + 8;
        }
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
        // Calculate page dimensions for centering
        $pageWidth = $this->pdf->getPageWidth();
        $pageHeight = $this->pdf->getPageHeight();
        $margins = $this->pdf->getMargins();
        $marginLeft = $margins['left'];
        $marginRight = $margins['right'];
        $contentWidth = $pageWidth - $marginLeft - $marginRight;

        // Title - already centered
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->SetFont('helvetica', 'B', 28);
        $this->pdf->Cell(0, 12, 'Our Agenda', 0, 1, 'C'); // Centered looks better for hero title

        // Subtitle - already centered
        $this->pdf->Ln(4);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', 'I', 13);
        $this->pdf->MultiCell(0, 7, "We are committed to providing the best services to grow your business.", 0, 'C');

        $this->pdf->Ln(12);

        // === Centered four columns layout ===
        $boxWidth = 45;           // width of each column
        $totalBoxesWidth = ($boxWidth * 4) + (18 * 3); // 4 boxes + 3 spacings
        $startX = ($pageWidth - $totalBoxesWidth) / 2; // Center the entire block horizontally
        $spacing = 18;           // space between columns
        $iconY = $this->pdf->GetY();
        $textY = $iconY + 28;  // where text starts under icons

        $items = [
            [
                'title' => 'Activities Calendar',
                'desc'  => "It's about visual representation of our commitments, Tasks and services provided to customer.",
                'icon'  => 'calendar'   // for reference
            ],
            [
                'title' => 'Statistics',
                'desc'  => "It's about tracking progress, measure performance, analyze problems and prioritize.",
                'icon'  => 'chart'
            ],
            [
                'title' => 'Tickets',
                'desc'  => "It's combination of practices, strategies and technologies to manage and analyze data through customer lifecycle.",
                'icon'  => 'ticket'
            ],
            [
                'title' => 'Support',
                'desc'  => "It's ultimately about making sure customers are successful in solving whatever issues they came to your business to help solve.",
                'icon'  => 'support'
            ]
        ];

        foreach ($items as $i => $item) {
            $x = $startX + ($i * ($boxWidth + $spacing));

            // 1. Draw orange circle (icon placeholder)
            $this->pdf->SetFillColor(255, 102, 0);
            $this->pdf->SetDrawColor(255, 102, 0);
            $this->pdf->Circle($x + $boxWidth / 2, $iconY + 12, 12, 0, 360, 'F'); // filled circle

            // Optional: white icon symbol (simple text approximation)
            $this->pdf->SetTextColor(255, 255, 255);
            $this->pdf->SetFont('helvetica', 'B', 20);

            $symbol = '📅'; // Activities Calendar
            if ($i === 1) $symbol = '📊';
            if ($i === 2) $symbol = '🎟';
            if ($i === 3) $symbol = '🛠';

            $this->pdf->Text($x + $boxWidth / 2 - 5, $iconY + 8, $symbol);

            // 2. Title under icon
            $this->pdf->SetXY($x, $textY);
            $this->pdf->SetTextColor(255, 102, 0);
            $this->pdf->SetFont('helvetica', 'B', 12);
            $this->pdf->Cell($boxWidth, 8, $item['title'], 0, 1, 'C');

            // 3. Description
            $this->pdf->SetXY($x, $textY + 10);
            $this->pdf->SetTextColor(0, 0, 0);
            $this->pdf->SetFont('helvetica', '', 9.5);
            $this->pdf->MultiCell($boxWidth, 5, $item['desc'], 0, 'C');
        }

        // Final spacing before next section
        $this->pdf->Ln(45);
    }

    private function addActivitiesCalendar()
    {
        $this->pdf->SetTextColor(255, 102, 0); // Orange
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->Cell(0, 10, 'Activities Calendar', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->Cell(0, 5, "It's about visual representation of our commitments, Tasks and services provided to customer.", 0, 1, 'L');

        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->Cell(0, 5, 'FLASHNET AMC CLIENT RECORD', 0, 1, 'L');
        $this->pdf->Cell(0, 5, 'Client: ' . $this->client_name, 0, 1, 'L');
        $this->pdf->Cell(0, 5, 'Year: ' . $this->year, 0, 1, 'L');
        $this->pdf->SetTextColor(128, 128, 128); // Gray for certification note
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->Cell(0, 5, 'An ISO 9001:2015 Certified Company', 0, 1, 'R');

        $this->pdf->Ln(5);

        // Table header
        $month_width = 35.625; // Original 30 + 25% - 5% = 35.625
        $day_width = 6.175; // Original 5.2 + 25% - 5% = 6.175
        $this->pdf->SetFillColor(0, 0, 128); // Dark navy for header
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->pdf->Cell($month_width, 5, 'Weekday/Month', 1, 0, 'C', true);

        $weekdays = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

        // Calculate maximum columns needed for any month in the year
        $max_cols_needed = 0;
        for ($m = 1; $m <= 12; $m++) {
            $dt = new DateTime($this->year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT) . '-01');
            $days_in_month = (int)$dt->format('t');
            $start_wday = (int)$dt->format('w'); // 0 = SUN, 6 = SAT
            $cols_needed = $start_wday + $days_in_month;
            if ($cols_needed > $max_cols_needed) {
                $max_cols_needed = $cols_needed;
            }
        }

        // Ensure we have at least 35 columns (5 weeks) but expand as needed
        $total_columns = max(35, $max_cols_needed);

        // Add weekday headers for calculated number of columns
        for ($i = 0; $i < $total_columns; $i++) {
            $weekday_index = $i % 7; // Cycle through SUN-SAT
            $this->pdf->Cell($day_width, 5.9375, $weekdays[$weekday_index], 1, 0, 'C', true); // Height reduced by 5% from 6.25
        }

        // Calculate maximum columns needed for any month in the year
        $max_cols_needed = 0;
        for ($calc_m = 1; $calc_m <= 12; $calc_m++) {
            $calc_dt = new DateTime($this->year . '-' . str_pad($calc_m, 2, '0', STR_PAD_LEFT) . '-01');
            $calc_days_in_month = (int)$calc_dt->format('t');
            $calc_start_wday = (int)$calc_dt->format('w'); // 0 = SUN, 6 = SAT
            $calc_cols_needed = $calc_start_wday + $calc_days_in_month;
            if ($calc_cols_needed > $max_cols_needed) {
                $max_cols_needed = $calc_cols_needed;
            }
        }

        // Ensure we have at least 35 columns (5 weeks) but expand as needed
        $total_columns = max(35, $max_cols_needed);

        $this->pdf->Ln();

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 8);

        // Get ticket data organized by date for calendar coloring
        $ticket_dates = [];
        foreach ($this->report_data['recent_tickets'] ?? [] as $ticket) {
            if (isset($ticket['created_at'])) {
                $ticket_date = date('Y-m-d', strtotime($ticket['created_at']));
                $category = $ticket['category'] ?? 'General';

                if (!isset($ticket_dates[$ticket_date])) {
                    $ticket_dates[$ticket_date] = [];
                }

                if (!isset($ticket_dates[$ticket_date][$category])) {
                    $ticket_dates[$ticket_date][$category] = 0;
                }
                $ticket_dates[$ticket_date][$category]++;
            }
        }

        // Calendar rows for each month
        for ($m = 1; $m <= 12; $m++) {
            $dt = new DateTime($this->year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT) . '-01');
            $month_name = $dt->format('F');
            $days_in_month = (int)$dt->format('t');
            $start_wday = (int)$dt->format('w'); // 0 = SUN, 6 = SAT

            $this->pdf->Cell($month_width, 5.9375, $month_name, 1, 0, 'L'); // Height reduced by 5% from 6.25

            // Blanks before the first day
            for ($b = 0; $b < $start_wday; $b++) {
                $this->pdf->Cell($day_width, 5.9375, '', 1, 0, 'C'); // Height reduced by 5% from 6.25
            }

            // Day numbers with color coding
            for ($d = 1; $d <= $days_in_month; $d++) {
                $current_date = sprintf('%04d-%02d-%02d', $this->year, $m, $d);
                $day_cell_value = $d;

                // Check if this date has tickets
                if (isset($ticket_dates[$current_date]) && !empty($ticket_dates[$current_date])) {
                    // Find the category with most tickets for this day
                    $max_category = array_keys($ticket_dates[$current_date], max($ticket_dates[$current_date]))[0];

                    // Set background color based on category
                    if (isset($this->category_colors[$max_category])) {
                        $bg_color = $this->category_colors[$max_category][0];
                        $text_color = $this->category_colors[$max_category][1];

                        // Set cell background color
                        $this->pdf->SetFillColor($bg_color[0], $bg_color[1], $bg_color[2]);
                        $this->pdf->SetTextColor($text_color[0], $text_color[1], $text_color[2]);
                        $this->pdf->Cell($day_width, 5.9375, $day_cell_value, 1, 0, 'C', true); // Height reduced by 5% from 6.25
                    } else {
                        // Default gray for unknown categories
                        $this->pdf->SetFillColor(169, 169, 169);
                        $this->pdf->SetTextColor(0, 0, 0);
                        $this->pdf->Cell($day_width, 5.9375, $day_cell_value, 1, 0, 'C', true); // Height reduced by 5% from 6.25
                    }
                } else {
                    // No tickets - white background
                    $this->pdf->SetFillColor(255, 255, 255);
                    $this->pdf->SetTextColor(0, 0, 0);
                    $this->pdf->Cell($day_width, 5.9375, $day_cell_value, 1, 0, 'C', true); // Height reduced by 5% from 6.25
                }
            }

            // Calculate how many blank cells are needed for this month
            $filled = $start_wday + $days_in_month;
            $blanks_after = $total_columns - $filled;
            for ($b = 0; $b < $blanks_after; $b++) {
                $this->pdf->SetFillColor(255, 255, 255);
                $this->pdf->SetTextColor(0, 0, 0);
                $this->pdf->Cell($day_width, 5.9375, '', 1, 0, 'C', true); // Height reduced by 5% from 6.25
            }

            $this->pdf->Ln();
        }

        // Key Statistics
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Cell(0, 5, 'KEY STATISTICS', 0, 1, 'L');

        // Calculate grouped ticket counts using actual categories from database
        $category_counts = [];

        // Get actual categories from database
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->query("SELECT DISTINCT category FROM tickets WHERE category IS NOT NULL AND category != '' ORDER BY category");
            $actual_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Initialize counts for all actual categories
            foreach ($actual_categories as $cat) {
                $category_counts[$cat] = 0;
            }
        } catch (Exception $e) {
            // Log error for debugging
            error_log("TicketReportPDFGenerator: Error fetching categories: " . $e->getMessage());
            // Fallback to common categories if database query fails
            $category_counts = [
                'General' => 0,
                'Hardware' => 0,
                'Network' => 0,
                'Server' => 0,
                'Firewall' => 0
            ];
        }

        // Count tickets by actual categories
        foreach ($this->report_data['recent_tickets'] ?? [] as $ticket) {
            $cat = $ticket['category'] ?? '';
            if (array_key_exists($cat, $category_counts)) {
                $category_counts[$cat]++;
            }
        }

        $total_tickets = $this->report_data['stats']['total_tickets'] ?? count($this->report_data['recent_tickets'] ?? []);

        // Define keys with colors for categories that exist in the system
        $keys = [
            'Total Tickets' => ['value' => $total_tickets, 'color' => [0, 0, 139], 'text_color' => [255, 255, 255]],
        ];

        // Add dynamic categories with predefined colors
        $category_colors = [
            'General' => [[255, 165, 0], [0, 0, 0]], // Orange
            'Hardware' => [[144, 238, 144], [0, 0, 0]], // Light green
            'Network' => [[173, 216, 230], [0, 0, 0]], // Light blue
            'Server' => [[255, 102, 0], [0, 0, 0]], // Orange-red
            'Firewall' => [[255, 0, 0], [255, 255, 255]], // Red
            'Email' => [[0, 0, 139], [255, 255, 255]], // Dark blue
            'Security' => [[75, 0, 130], [255, 255, 255]], // Indigo
            'CCTV' => [[128, 0, 128], [255, 255, 255]], // Purple
            'Biometric' => [[255, 20, 147], [0, 0, 0]], // Deep pink
            'Software' => [[60, 179, 113], [0, 0, 0]], // Medium sea green
        ];

        // Category colors already initialized in constructor
        // $this->category_colors = $category_colors;

        // Add existing categories to keys
        foreach ($category_counts as $category => $count) {
            if (isset($this->category_colors[$category])) {
                $keys[$category] = [
                    'value' => $count,
                    'color' => $this->category_colors[$category][0],
                    'text_color' => $this->category_colors[$category][1]
                ];
            } else {
                // Use default color for unknown categories
                $keys[$category] = [
                    'value' => $count,
                    'color' => [169, 169, 169], // Gray
                    'text_color' => [0, 0, 0]
                ];
            }
        }

        $box_width = 25; // Adjust as needed to fit

        // Draw the number boxes
        $this->pdf->SetFont('helvetica', 'B', 14);
        foreach ($keys as $label => $info) {
            $this->pdf->SetFillColor($info['color'][0], $info['color'][1], $info['color'][2]);
            $this->pdf->SetTextColor($info['text_color'][0], $info['text_color'][1], $info['text_color'][2]);
            $this->pdf->Cell($box_width, 15, $info['value'], 1, 0, 'C', true);
        }

        $this->pdf->Ln();

        // Draw the labels below
        $this->pdf->SetFont('helvetica', '', 8);
        $this->pdf->SetTextColor(0, 0, 0);
        foreach ($keys as $label => $info) {
            $this->pdf->Cell($box_width, 5, $label, 0, 0, 'C');
        }

        $this->pdf->Ln();
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
        $this->pdf->Cell(110, 10, 'Subject', 1, 0, 'C', true);  // Combined width of Client Name (50) + Subject (60)
        $this->pdf->Cell(30, 10, 'Status', 1, 0, 'C', true);
        $this->pdf->Cell(40, 10, 'Assigned to', 1, 1, 'C', true);

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('helvetica', '', 9);
        $tickets = $this->report_data['recent_tickets'] ?? [];
        $fill = false;
        foreach ($tickets as $ticket) {
            $this->pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            $this->pdf->Cell(30, 10, date('d/m/Y', strtotime($ticket['created_at'])), 1, 0, 'C', true);
            $this->pdf->Cell(110, 10, substr($ticket['title'] ?? 'No Subject', 0, 60), 1, 0, 'L', true);  // Increased width for subject
            $this->pdf->Cell(30, 10, $ticket['status'] ?? 'Unknown', 1, 0, 'C', true);
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
            $this->pdf->Ln(10);
            $this->pdf->SetFont('helvetica', 'B', 12);
            $this->pdf->MultiCell(0, 5, $this->custom_suggestions, 0, 'L');
        } else {
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
        if (
            !empty($this->detailed_suggestion_title) || !empty($this->detailed_suggestion_description) ||
            !empty($this->before_image_path) || !empty($this->after_image_path) || !empty($this->additional_comments)
        ) {

            if (!empty($this->detailed_suggestion_title)) {
                $this->pdf->SetFont('helvetica', 'B', 16);
                $this->pdf->Cell(0, 10, $this->detailed_suggestion_title, 0, 1, 'L');
            }

            if (!empty($this->detailed_suggestion_description)) {
                $this->pdf->SetFont('helvetica', '', 12);
                $this->pdf->MultiCell(0, 5, $this->detailed_suggestion_description, 0, 'L');
            }

            $this->pdf->Ln(5);

            if (!empty($this->before_image_path)) {
                $this->pdf->SetFont('helvetica', 'B', 12);
                $this->pdf->Cell(0, 5, 'Current State', 0, 1, 'L');
                $this->pdf->SetFont('helvetica', '', 10);
                $this->pdf->MultiCell(0, 5, 'Current situation showing the issue that needs to be addressed.', 0, 'L');

                $imagePath = __DIR__ . $this->before_image_path;
                if (file_exists($imagePath)) {
                    // Get image dimensions
                    $imgSize = getimagesize($imagePath);
                    if ($imgSize !== false) {
                        $imgWidth = min(80, $imgSize[0] / 4); // Scale down if needed
                        $imgHeight = ($imgSize[1] / $imgSize[0]) * $imgWidth; // Maintain aspect ratio
                        $this->pdf->Image($imagePath, $this->pdf->GetX(), $this->pdf->GetY(), $imgWidth, $imgHeight);
                        $this->pdf->Ln($imgHeight + 5); // Add space after image
                    }
                } else {
                    // Log the missing image path for debugging
                    error_log("Missing image file: $imagePath");
                }
            }

            if (!empty($this->after_image_path)) {
                $this->pdf->SetFont('helvetica', 'B', 12);
                $this->pdf->Cell(0, 5, 'How should be ?', 0, 1, 'L');
                $this->pdf->SetFont('helvetica', '', 10);
                $this->pdf->MultiCell(0, 5, 'The recommended solution or improvement.', 0, 'L');

                $imagePath = __DIR__ . $this->after_image_path;
                if (file_exists($imagePath)) {
                    // Get image dimensions
                    $imgSize = getimagesize($imagePath);
                    if ($imgSize !== false) {
                        $imgWidth = min(80, $imgSize[0] / 4); // Scale down if needed
                        $imgHeight = ($imgSize[1] / $imgSize[0]) * $imgWidth; // Maintain aspect ratio
                        $this->pdf->Image($imagePath, $this->pdf->GetX(), $this->pdf->GetY(), $imgWidth, $imgHeight);
                        $this->pdf->Ln($imgHeight + 5); // Add space after image
                    }
                } else {
                    // Log the missing image path for debugging
                    error_log("Missing image file: $imagePath");
                }
            }

            if (!empty($this->additional_comments)) {
                $this->pdf->SetFont('helvetica', 'B', 14);
                $this->pdf->Cell(0, 10, 'Additional Notes', 0, 1, 'L');
                $this->pdf->SetFont('helvetica', '', 12);
                $this->pdf->MultiCell(0, 5, $this->additional_comments, 0, 'L');
            }
        } else {
            $this->pdf->SetFont('helvetica', 'B', 16);
            $this->pdf->Cell(0, 10, 'Detailed Suggestion', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', 'I', 12);
            $this->pdf->SetTextColor(128, 128, 128);
            $this->pdf->Cell(0, 5, 'No detailed suggestions provided', 0, 1, 'L');
            $this->pdf->SetTextColor(0, 0, 0);
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
        $stats = $this->report_data['stats'] ?? [];
        $total_tickets = $stats['total_tickets'] ?? 0;

        if ($total_tickets <= 0) {
            return 100;
        }

        $resolved_rate = (($stats['resolved_tickets'] ?? 0) + ($stats['closed_tickets'] ?? 0)) / $total_tickets;
        $critical_rate = ($stats['critical_tickets'] ?? 0) / $total_tickets;
        $open_rate = ($stats['open_tickets'] ?? 0) / $total_tickets;

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
// Clean the output buffer if it was started in this file
if (ob_get_level() > 0) {
    ob_end_clean();
}
