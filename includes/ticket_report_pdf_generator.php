<?php
require_once __DIR__ . '/../vendor/autoload.php';  // Autoloader for TCPDF
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';  // Include auth functions to get current user info

class TicketReportPDFGenerator
{
    private $pdf;
    private $report_data;
    private $filters;

    public function __construct($report_data, $filters)
    {
        $this->report_data = $report_data;
        $this->filters = $filters;

        // Initialize TCPDF with standard A4 landscape format for better report layout
        $this->pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $this->pdf->SetCreator(PDF_CREATOR);
        $this->pdf->SetTitle('Ticket Report - ' . ($this->filters['client_id'] ?? 'All Clients'));
        $this->pdf->SetAuthor('MSP Application');

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

        // 2. Executive Summary / Key Insights
        $this->addExecutiveSummary();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 3. KPI Metrics / Statistics Grid
        $this->addKPIMetrics();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 4. Visual Analytics (as text/tables representation)
        $this->addVisualAnalytics();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 6. Detailed Tickets Table (Core Data Section)
        $this->addDetailedTicketsTable();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 7. Insights / Recommendations Section
        $this->addInsightsRecommendations();

        // Add page break to separate sections
        $this->pdf->AddPage();

        // 8. Services Overview / Informational Footer
        $this->addFooterSection();

        return $this->pdf;
    }

    private function addHeaderSection()
    {
        $pageWidth  = $this->pdf->getPageWidth();
        $pageHeight = $this->pdf->getPageHeight();
        $margin     = 20;
        $contentWidth = $pageWidth - ($margin * 2);

        /* =========================
       LOGO (CENTERED)
    ========================== */
        $logoPath = __DIR__ . '/../assets/logo.png';
        if (file_exists($logoPath)) {
            $logoWidth  = 60;
            $logoHeight = 30;

            $logoX = ($pageWidth - $logoWidth) / 2;
            $logoY = 35;

            $this->pdf->Image(
                $logoPath,
                $logoX,
                $logoY,
                $logoWidth,
                $logoHeight,
                'PNG'
            );
        }

        /* =========================
       MAIN TITLE
    ========================== */
        $this->pdf->SetTextColor(0, 78, 137); // Brand Blue
        $this->pdf->SetFont('helvetica', 'B', 30);
        $this->pdf->SetXY($margin, 90);
        $this->pdf->Cell(
            $contentWidth,
            12,
            'MANAGED IT SERVICES',
            0,
            1,
            'C'
        );

        /* =========================
       TAGLINE
    ========================== */
        $this->pdf->SetFont('helvetica', 'I', 18);
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
       REPORT TITLE (DYNAMIC)
    ========================== */
        $startDate = new DateTime($this->filters['start_date'] ?? date('Y-m-d'));
        $endDate   = new DateTime($this->filters['end_date'] ?? date('Y-m-d'));

        if ($startDate->format('Y-m') === $endDate->format('Y-m')) {
            $reportTitle = 'AMC ' . $startDate->format('F Y') . ' Report';
        } elseif ($startDate->format('Y') === $endDate->format('Y')) {
            $reportTitle = 'AMC ' . $startDate->format('F') . ' - ' . $endDate->format('F Y') . ' Report';
        } else {
            $reportTitle = 'AMC ' . $startDate->format('M Y') . ' - ' . $endDate->format('M Y') . ' Report';
        }

        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->SetTextColor(0, 78, 137);
        $this->pdf->Cell(
            $contentWidth,
            12,
            $reportTitle,
            0,
            1,
            'C'
        );

        /* =========================
       WEBSITE
    ========================== */
        $this->pdf->Ln(8);
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

        /* =========================
       DECORATIVE DIVIDER
    ========================== */
        $this->pdf->Ln(15);
        $this->pdf->SetDrawColor(0, 78, 137);
        $this->pdf->SetLineWidth(0.8);
        $this->pdf->Line(
            $margin,
            $this->pdf->GetY(),
            $pageWidth - $margin,
            $this->pdf->GetY()
        );
    }

    private function addExecutiveSummary()
    {
        $this->pdf->SetTextColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->Cell(0, 10, 'Executive Summary', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0); // Reset to black
        $this->pdf->SetFont('helvetica', '', 12);

        // Key insights as bullet points
        $total_tickets = $this->report_data['stats']['total_tickets'] ?? 0;
        $open_tickets = $this->report_data['stats']['open_tickets'] ?? 0;
        $resolved_tickets = $this->report_data['stats']['resolved_tickets'] ?? 0;
        $avg_resolution = $this->report_data['stats']['avg_resolution_days'] ?? 0;

        $open_percentage = $total_tickets > 0 ? round(($open_tickets / $total_tickets) * 100, 1) : 0;

        $this->pdf->Ln(3);
        $this->pdf->Cell(0, 7, '• ' . $open_percentage . '% of tickets are still open', 0, 1, 'L');
        $this->pdf->Cell(0, 7, '• Total tickets processed: ' . $total_tickets, 0, 1, 'L');
        $this->pdf->Cell(0, 7, '• Tickets resolved: ' . $resolved_tickets, 0, 1, 'L');
        $this->pdf->Cell(0, 7, '• Average resolution time: ' . round($avg_resolution, 1) . ' days', 0, 1, 'L');

        // Ticket Health Score
        $health_score = $this->calculateHealthScore();
        $this->pdf->SetTextColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->pdf->Cell(0, 10, 'Ticket Health Score: ' . $health_score . ' / 100', 0, 1, 'L');
    }

    private function addKPIMetrics()
    {
        $this->pdf->SetTextColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->Cell(0, 10, 'KPI Metrics', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0); // Reset to black

        // Prepare data for KPIs
        $stats = $this->report_data['stats'] ?? [];
        $total_tickets = $stats['total_tickets'] ?? 0;

        // Define KPIs
        $kpis = [
            ['label' => 'Total Tickets', 'value' => $stats['total_tickets'] ?? 0],
            ['label' => 'Open Tickets', 'value' => $stats['open_tickets'] ?? 0],
            ['label' => 'Resolved Tickets', 'value' => $stats['resolved_tickets'] ?? 0],
            ['label' => 'Avg Resolution Time', 'value' => round($stats['avg_resolution_days'] ?? 0, 1) . ' days'],
            ['label' => 'High Priority', 'value' => $stats['high_priority_tickets'] ?? 0],
            ['label' => 'Critical Priority', 'value' => $stats['critical_tickets'] ?? 0]
        ];

        // Create a table for KPIs with Flashnet colors
        $this->pdf->SetFillColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetTextColor(255, 255, 255); // White text
        $this->pdf->SetFont('helvetica', 'B', 10);

        // Header row
        $this->pdf->Cell(85, 10, 'Metric', 1, 0, 'L', true);
        $this->pdf->Cell(45, 10, 'Value', 1, 1, 'C', true);

        // Reset colors for data rows
        $this->pdf->SetTextColor(0, 0, 0); // Black text
        
        $fill = false;
        foreach ($kpis as $kpi) {
            // Alternating row colors
            $this->pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255); // Light gray and white alternating
            $this->pdf->Cell(85, 10, $kpi['label'], 1, 0, 'L', true);
            $this->pdf->Cell(45, 10, $kpi['value'], 1, 1, 'C', true);
            $fill = !$fill; // Toggle for next row
        }
    }

    private function addVisualAnalytics()
    {
        $this->pdf->SetTextColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->Cell(0, 10, 'Ticket Analysis', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0); // Reset to black

        // Priority Distribution
        $priority_dist = $this->report_data['priority_distribution'] ?? [];
        if (!empty($priority_dist)) {
            $this->pdf->SetFont('helvetica', 'B', 12);
            $this->pdf->Cell(0, 6, 'Priority Distribution:', 0, 1, 'L');

            $this->pdf->SetFont('helvetica', '', 10);
            foreach ($priority_dist as $dist) {
                $this->pdf->Cell(0, 5, $dist['priority'] . ': ' . $dist['count'] . ' tickets (' . $dist['percentage'] . '%)', 0, 1, 'L');
            }
        }

        // Status Distribution
        $status_dist = $this->report_data['status_distribution'] ?? [];
        if (!empty($status_dist)) {
            $this->pdf->SetFont('helvetica', 'B', 12);
            $this->pdf->Cell(0, 6, 'Status Distribution:', 0, 1, 'L');

            $this->pdf->SetFont('helvetica', '', 10);
            foreach ($status_dist as $dist) {
                $this->pdf->Cell(0, 5, $dist['status'] . ': ' . $dist['count'] . ' tickets (' . $dist['percentage'] . '%)', 0, 1, 'L');
            }
        }
    }

    private function addDetailedTicketsTable()
    {
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->pdf->Cell(0, 8, 'Detailed Tickets', 0, 1, 'L');

        // Improved table header with Flashnet colors
        $this->pdf->SetFillColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetTextColor(255, 255, 255); // White text
        $this->pdf->SetFont('helvetica', 'B', 10);

        // Header row with Flashnet branding
        $this->pdf->Cell(35, 10, 'Ticket #', 1, 0, 'C', true);
        $this->pdf->Cell(25, 10, 'Client', 1, 0, 'C', true);
        $this->pdf->Cell(45, 10, 'Subject', 1, 0, 'C', true);
        $this->pdf->Cell(20, 10, 'Priority', 1, 0, 'C', true);
        $this->pdf->Cell(25, 10, 'Status', 1, 0, 'C', true);
        $this->pdf->Cell(25, 10, 'Created', 1, 0, 'C', true);
        $this->pdf->Cell(25, 10, 'Assigned To', 1, 1, 'C', true);

        // Reset colors for table data
        $this->pdf->SetTextColor(0, 0, 0); // Black text
        $this->pdf->SetFont('helvetica', '', 9);

        $tickets = $this->report_data['recent_tickets'] ?? [];

        if (empty($tickets)) {
            $this->pdf->Cell(190, 10, 'No tickets found for the selected criteria.', 1, 1, 'C');
        } else {
            $fill = false;
            foreach ($tickets as $ticket) {
                // Alternating row colors
                $this->pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255); // Light gray and white alternating

                $this->pdf->Cell(35, 10, $ticket['ticket_number'] ?? $ticket['id'], 1, 0, 'C', true);
                $this->pdf->Cell(25, 10, substr($ticket['company_name'] ?? 'Internal', 0, 15), 1, 0, 'L', true);
                $this->pdf->Cell(45, 10, substr($ticket['title'] ?? 'No Subject', 0, 25), 1, 0, 'L', true);
                $this->pdf->Cell(20, 10, $ticket['priority'], 1, 0, 'C', true);
                $this->pdf->Cell(25, 10, $ticket['status'], 1, 0, 'C', true);
                $this->pdf->Cell(25, 10, date('M d, Y', strtotime($ticket['created_at'])), 1, 0, 'C', true);
                $this->pdf->Cell(25, 10, substr($ticket['assigned_to'] ?? 'Unassigned', 0, 20), 1, 1, 'C', true);

                $fill = !$fill; // Toggle for next row
            }
        }
    }

    private function addInsightsRecommendations()
    {
        $this->pdf->SetTextColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->Cell(0, 10, 'Insights & Recommendations', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0); // Reset to black
        $this->pdf->SetFont('helvetica', '', 10);

        // Generate recommendations based on data
        $stats = $this->report_data['stats'] ?? [];
        $total_tickets = $stats['total_tickets'] ?? 0;
        $open_tickets = $stats['open_tickets'] ?? 0;
        $resolved_tickets = $stats['resolved_tickets'] ?? 0;

        $recommendations = [];

        if ($total_tickets > 0 && ($open_tickets / $total_tickets) > 0.3) {
            $recommendations[] = 'High percentage of open tickets. Consider reassigning or escalating pending tickets.';
        }

        if ($stats['critical_tickets'] > 0) {
            $recommendations[] = 'Attention needed for ' . $stats['critical_tickets'] . ' critical priority tickets.';
        }

        if ($stats['avg_resolution_days'] > 5) {
            $recommendations[] = 'Average resolution time is high (' . round($stats['avg_resolution_days'], 1) . ' days). Review processes.';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Ticket handling is performing well. Keep up the good work!';
        }

        foreach ($recommendations as $idx => $rec) {
            $this->pdf->Cell(0, 7, ($idx + 1) . '. ' . $rec, 0, 1, 'L');
        }
    }

    private function addFooterSection()
    {
        // Add a separator line with Flashnet color
        $this->pdf->SetDrawColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetLineWidth(0.5);
        $this->pdf->Ln(5);
        $this->pdf->Line(15, $this->pdf->GetY(), 282, $this->pdf->GetY()); // Line from left margin to right margin
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->Ln(5);

        $this->pdf->SetTextColor(0, 78, 137); // Flashnet blue
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->pdf->Cell(0, 8, 'Managed IT Services', 0, 1, 'C');

        $this->pdf->SetTextColor(0, 0, 0); // Reset to black
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(
            0,
            6,
            "Thank you for choosing our managed IT services.\n\n" .
                "For any inquiries regarding this report, please contact our support team.\n\n" .
                "Report generated on: " . date('Y-m-d H:i:s') . "\n" .
                "© " . date('Y') . " Flashnet – Managed IT Services. All rights reserved.",
            0,
            'C',
            false,
            0,
            '',
            '',
            true,
            0,
            false,
            true,
            0
        );
    }

    private function calculateHealthScore()
    {
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
}
