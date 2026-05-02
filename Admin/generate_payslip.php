<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Handle flash messages from session
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
if ($flashMessage) {
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

$adminID = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data for profile picture
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $_SESSION['userID'])->fetch_assoc();

// Fetch all employees (sorted by name)
$employees = $conn->query("SELECT userID, Firstname, Lastname, Email FROM customers WHERE Role = 'employee' ORDER BY Firstname, Lastname");

// Fetch all payroll records with employee info (for viewing existing payslips)
$allPayrolls = $conn->query("
    SELECT p.*, c.Firstname, c.Lastname, c.Email 
    FROM payroll p 
    JOIN customers c ON p.userID = c.userID 
    WHERE c.Role = 'employee'
    ORDER BY c.Firstname, c.Lastname, p.period_end DESC
");

// Handle payslip generation (show preview)
$payslipHTML = null;
$selectedPayroll = null;
if (isset($_POST['generate_payslip'])) {
    $userID = intval($_POST['userID']);
    $periodStart = $_POST['period_start'];
    $periodEnd = $_POST['period_end'];
    
    // Check if payslip already exists
    $checkStmt = $conn->prepare("
        SELECT payrollID FROM payroll 
        WHERE userID = ? AND period_start = ? AND period_end = ?
    ");
    $checkStmt->bind_param("iss", $userID, $periodStart, $periodEnd);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();
    
    if ($exists) {
        $_SESSION['flash_message'] = "Payslip already exists for this employee and period!";
        $_SESSION['flash_type'] = "warning";
        header("Location: generate_payslip.php");
        exit();
    }
    
    // Get payroll record (should not exist, but just in case)
    $payrollStmt = $conn->prepare("
        SELECT p.*, c.Firstname, c.Lastname, c.Email 
        FROM payroll p 
        JOIN customers c ON p.userID = c.userID 
        WHERE p.userID = ? AND p.period_start = ? AND p.period_end = ?
    ");
    $payrollStmt->bind_param("iss", $userID, $periodStart, $periodEnd);
    $payrollStmt->execute();
    $payroll = $payrollStmt->get_result()->fetch_assoc();
    $payrollStmt->close();
    
    if ($payroll) {
        $payslipHTML = generatePayslipHTML($payroll, $periodStart, $periodEnd);
        $selectedPayroll = $payroll;
    } else {
        $_SESSION['flash_message'] = "No payroll record found for the selected period.";
        $_SESSION['flash_type'] = "error";
        header("Location: generate_payslip.php");
        exit();
    }
}

// Handle viewing specific payslip from modal
if (isset($_GET['view_payslip'])) {
    $payrollID = intval($_GET['view_payslip']);
    $payrollStmt = $conn->prepare("
        SELECT p.*, c.Firstname, c.Lastname, c.Email 
        FROM payroll p 
        JOIN customers c ON p.userID = c.userID 
        WHERE p.payrollID = ?
    ");
    $payrollStmt->bind_param("i", $payrollID);
    $payrollStmt->execute();
    $payroll = $payrollStmt->get_result()->fetch_assoc();
    $payrollStmt->close();
    
    if ($payroll) {
        $payslipHTML = generatePayslipHTML($payroll, $payroll['period_start'], $payroll['period_end']);
        $selectedPayroll = $payroll;
    }
}

// Handle Bulk Payslip Generation
if (isset($_POST['bulk_generate'])) {
    $selectedEmployees = $_POST['selected_employees'] ?? [];
    $periodStart = $_POST['bulk_period_start'];
    $periodEnd = $_POST['bulk_period_end'];
    
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    
    foreach ($selectedEmployees as $userID) {
        $userID = intval($userID);
        
        // Check if payroll already exists for this period
        $checkStmt = $conn->prepare("
            SELECT payrollID FROM payroll 
            WHERE userID = ? AND period_start = ? AND period_end = ?
        ");
        $checkStmt->bind_param("iss", $userID, $periodStart, $periodEnd);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
        
        if ($exists) {
            // Get employee name for better error message
            $nameStmt = $conn->prepare("SELECT CONCAT(Firstname, ' ', Lastname) as full_name FROM customers WHERE userID = ?");
            $nameStmt->bind_param("i", $userID);
            $nameStmt->execute();
            $empName = $nameStmt->get_result()->fetch_assoc()['full_name'] ?? "Employee #$userID";
            $nameStmt->close();
            
            $errors[] = "$empName - Payslip already exists for this period";
            $errorCount++;
            continue;
        }
        
        // Calculate attendance for the period
        $hoursStmt = $conn->prepare("
            SELECT COALESCE(SUM(total_hours), 0) as total_hours 
            FROM attendance 
            WHERE userID = ? AND DATE(clock_in) BETWEEN ? AND ?
        ");
        $hoursStmt->bind_param("iss", $userID, $periodStart, $periodEnd);
        $hoursStmt->execute();
        $hoursResult = $hoursStmt->get_result()->fetch_assoc();
        $totalHours = $hoursResult['total_hours'] ?? 0;
        $hoursStmt->close();
        
        // Get employee rate
        $empStmt = $conn->prepare("SELECT hourly_rate, daily_rate FROM customers WHERE userID = ?");
        $empStmt->bind_param("i", $userID);
        $empStmt->execute();
        $empData = $empStmt->get_result()->fetch_assoc();
        $empStmt->close();
        
        $hourlyRate = $empData['hourly_rate'] ?? 100.00;
        $dailyRate = $empData['daily_rate'] ?? 800.00;
        
        // Calculate pay
        $grossPay = $totalHours * $hourlyRate;
        $deductions = $grossPay * 0.10; // 10% statutory deductions
        $netPay = $grossPay - $deductions;
        
        // Insert payroll record
        $insertStmt = $conn->prepare("
            INSERT INTO payroll (userID, period_start, period_end, payroll_cycle, total_hours, hourly_rate, daily_rate, gross_pay, deductions, net_pay, status)
            VALUES (?, ?, ?, 'Monthly', ?, ?, ?, ?, ?, ?, 'Processed')
        ");
        $insertStmt->bind_param("issdddddd", $userID, $periodStart, $periodEnd, $totalHours, $hourlyRate, $dailyRate, $grossPay, $deductions, $netPay);
        
        if ($insertStmt->execute()) {
            $successCount++;
        } else {
            $errors[] = "Failed to generate payslip for employee ID $userID";
            $errorCount++;
        }
        $insertStmt->close();
    }
    
    if ($successCount > 0) {
        $message = $successCount . ' payslip(s) generated successfully!';
        if ($errorCount > 0) {
            $message .= ' | ' . $errorCount . ' failed. Errors: ' . implode(' | ', $errors);
        }
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = "success";
    } else {
        $_SESSION['flash_message'] = 'No payslips were generated. Errors: ' . implode(' | ', $errors);
        $_SESSION['flash_type'] = "error";
    }
    header("Location: generate_payslip.php");
    exit();
}

// Handle Mark as Paid
if (isset($_GET['mark_paid'])) {
    $payrollID = intval($_GET['mark_paid']);
    
    $updateStmt = $conn->prepare("
        UPDATE payroll 
        SET status = 'Paid', payment_date = CURDATE() 
        WHERE payrollID = ?
    ");
    $updateStmt->bind_param("i", $payrollID);
    
    if ($updateStmt->execute()) {
        $_SESSION['flash_message'] = "Payslip marked as Paid!";
        $_SESSION['flash_type'] = "success";
    } else {
        $_SESSION['flash_message'] = "Failed to update payslip status.";
        $_SESSION['flash_type'] = "error";
    }
    header("Location: generate_payslip.php");
    exit();
    $updateStmt->close();
    exit();
}

function generatePayslipHTML($payroll, $periodStart, $periodEnd) {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payslip - ' . htmlspecialchars($payroll['Firstname'] . ' ' . $payroll['Lastname']) . '</title>
    <style>
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 14px; color: #333; max-width: 800px; margin: 40px auto; padding: 30px; border: 1px solid #ddd; }
        .header { text-align: center; border-bottom: 3px solid #0077B6; padding-bottom: 20px; margin-bottom: 30px; }
        .company { font-size: 26px; font-weight: 700; color: #0077B6; }
        .title { font-size: 20px; font-weight: 600; color: #023E8A; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 12px 15px; text-align: left; border: 1px solid #ddd; }
        th { background-color: #f0f7ff; font-weight: 600; color: #023E8A; }
        .amount { text-align: right; font-weight: 600; }
        .total-row { background-color: #e8f4ff; font-weight: 700; font-size: 16px; }
        .net-pay { background-color: #d4edda; color: #155724; font-size: 18px; font-weight: 700; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 11px; color: #888; text-align: center; }
        .print-btn { background: #0077B6; color: white; padding: 12px 30px; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">DE CHAVEZ WATERHAUS</div>
        <div>Water Delivery & Refilling Station</div>
        <div class="title">OFFICIAL EMPLOYEE PAYSLIP</div>
    </div>
    
    <table>
        <tr><th style="width: 200px;">Employee Name</th><td>' . htmlspecialchars($payroll['Firstname'] . ' ' . $payroll['Lastname']) . '</td></tr>
        <tr><th>Employee ID</th><td>' . $payroll['userID'] . '</td></tr>
        <tr><th>Email Address</th><td>' . htmlspecialchars($payroll['Email']) . '</td></tr>
        <tr><th>Pay Period</th><td>' . date('F d, Y', strtotime($periodStart)) . ' — ' . date('F d, Y', strtotime($periodEnd)) . '</td></tr>
    </table>
    
    <table>
        <tr><th>Description</th><th style="width: 150px; text-align: right;">Amount</th></tr>
        <tr><td>Total Hours Worked</td><td class="amount">' . number_format($payroll['total_hours'], 2) . ' hours</td></tr>
        <tr><td>Hourly Rate</td><td class="amount">₱' . number_format($payroll['hourly_rate'], 2) . ' / hour</td></tr>
        <tr style="background: #f8f9fa;"><td><strong>GROSS PAY</strong></td><td class="amount"><strong>₱' . number_format($payroll['gross_pay'], 2) . '</strong></td></tr>
    </table>
    
    <table>
        <tr><th>Description</th><th style="width: 150px; text-align: right;">Amount</th></tr>
        <tr><td><strong>Statutory Deductions (10%)</strong><br><small style="color: #666;">SSS, PhilHealth, Pag-IBIG, Withholding Tax</small></td><td class="amount">-₱' . number_format($payroll['deductions'], 2) . '</td></tr>
        <tr style="background: #fff3cd;"><td><strong>TOTAL DEDUCTIONS</strong></td><td class="amount"><strong>-₱' . number_format($payroll['deductions'], 2) . '</strong></td></tr>
    </table>
    
    <table class="total-row net-pay">
        <tr><th>NET PAY (Take-Home Amount)</th><th class="amount" style="font-size: 20px;">₱' . number_format($payroll['net_pay'], 2) . '</th></tr>
    </table>
    
    <div class="footer">
        <p><strong>Generated on:</strong> ' . date('F d, Y \a\t h:i A') . '</p>
        <p>This is a computer-generated document. No signature required.</p>
        <p><strong>De Chavez Waterhaus</strong> • Contact: support@dechavezwaterhaus.com</p>
    </div>
    
    <div style="text-align: center; margin-top: 30px;">
        <button onclick="window.print()" class="print-btn"><i class="fas fa-print me-2"></i> Print Payslip</button>
    </div>
</body>
</html>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Payslip • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        .sidebar { 
            position: fixed; top: 0; left: 0; height: 100vh; width: 260px; 
            background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; 
            transition: all 0.3s ease; 
            display: flex;
            flex-direction: column;
        }
        .sidebar .nav-menu {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 20px;
        }
        .sidebar .logout-section {
            padding: 15px 10px;
            border-top: 1px solid #eee;
            background: white;
        }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; }
        .sidebar .nav-link { 
            color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; 
            font-weight: 500; transition: all 0.3s ease; border-radius: 12px; margin: 4px 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            background-color: #f0f7ff; color: var(--primary); 
        }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        
        .sidebar .nav-link {
            padding: 12px 18px;
            margin: 2px 8px;
            border-radius: 10px;
        }
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.jpg" alt="Logo" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover;">
            <div>
                <span class="fw-bold fs-5">De Chavez Waterhaus</span>
                <small class="d-block text-muted">Admin Panel</small>
            </div>
        </div>
        
        <div class="nav-menu px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Manage Products</span></a></li>
                <li class="nav-item"><a href="manage_orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Manage Orders</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Employees</span></a></li>
                <li class="nav-item"><a href="attendance_management.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payroll_management.php" class="nav-link"><i class="fas fa-money-bill me-3"></i> <span>Payroll</span></a></li>
                <li class="nav-item"><a href="generate_payslip.php" class="nav-link active"><i class="fas fa-file-pdf me-3"></i> <span>Generate Payslip</span></a></li>
                <li class="nav-item"><a href="leave_management.php" class="nav-link"><i class="fas fa-calendar-alt me-3"></i> <span>Manage Leave</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar me-3"></i> <span>Reports & Analytics</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user me-3"></i> <span>My Profile</span></a></li>
            </ul>
        </div>
        
        <div class="logout-section">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt me-3"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Flash Alert -->
        <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo $flashType === 'success' ? 'success' : ($flashType === 'error' ? 'danger' : ($flashType === 'warning' ? 'warning' : 'info')); ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
            <div class="d-flex align-items-center">
                <i class="fas fa-<?php echo $flashType === 'success' ? 'check-circle' : ($flashType === 'error' ? 'exclamation-circle' : ($flashType === 'warning' ? 'exclamation-triangle' : 'info-circle')); ?> me-3 fa-lg"></i>
                <div><?php echo htmlspecialchars($flashMessage); ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <!-- Top Navbar with Notification Bell -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">Generate Payslip</h4>
                    <p class="text-muted mb-0">Create official payslips for employees</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn btn-light position-relative" data-bs-toggle="dropdown" style="width: 42px; height: 42px; border-radius: 12px;">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php 
                        $unreadCount = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE userID = $adminID AND is_read = 0")->fetch_assoc()['count'] ?? 0;
                        if ($unreadCount > 0): 
                        ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 9px; padding: 2px 6px;">
                                <?php echo min($unreadCount, 9); ?><?php echo $unreadCount > 9 ? '+' : ''; ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" style="width: 320px; max-height: 400px; overflow-y: auto;">
                        <li class="dropdown-header fw-bold">Notifications</li>
                        <?php 
                        $notifs = $conn->query("SELECT * FROM notifications WHERE userID = $adminID ORDER BY created_at DESC LIMIT 5");
                        if ($notifs->num_rows > 0):
                            while ($n = $notifs->fetch_assoc()):
                        ?>
                            <li><a class="dropdown-item small" href="notifications.php"><?php echo htmlspecialchars($n['message']); ?></a></li>
                        <?php endwhile; else: ?>
                            <li><span class="dropdown-item text-muted small">No new notifications</span></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center small text-primary" href="notifications.php">View All</a></li>
                    </ul>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                        <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                            <img src="../<?php echo $admin['profile_picture']; ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                <span class="fw-bold fs-6"><?php echo strtoupper(substr($adminName, 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="text-start d-none d-md-block">
                            <div class="fw-semibold"><?php echo htmlspecialchars($adminName); ?></div>
                            <small class="text-muted">Administrator</small>
                        </div>
                        <i class="fas fa-chevron-down fa-sm text-muted ms-1"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Generate Payslip Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="fw-bold mb-0"><i class="fas fa-plus-circle me-2"></i> Generate New Payslip</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Select Employee</label>
                                <select name="userID" class="form-select" required>
                                    <option value="">Choose employee...</option>
                                    <?php 
                                    // Reset employees result pointer
                                    $employees->data_seek(0);
                                    while ($emp = $employees->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $emp['userID']; ?>">
                                            <?php echo htmlspecialchars($emp['Firstname'] . ' ' . $emp['Lastname']); ?> 
                                            (<?php echo $emp['Email']; ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Period Start</label>
                                <input type="date" name="period_start" class="form-control" value="<?php echo date('Y-m-01'); ?>" required>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Period End</label>
                                <input type="date" name="period_end" class="form-control" value="<?php echo date('Y-m-t'); ?>" required>
                            </div>
                            
                            <div class="col-md-2">
                                <button type="submit" name="generate_payslip" class="btn btn-primary w-100">
                                    <i class="fas fa-file-invoice-dollar me-1"></i> Generate
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Payslip Generation Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold mb-0"><i class="fas fa-users me-2"></i> Bulk Payslip Generation</h5>
                            <span class="badge bg-info">Select multiple employees</span>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" id="bulkForm">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Period Start</label>
                                    <input type="date" name="bulk_period_start" class="form-control" value="<?php echo date('Y-m-01'); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Period End</label>
                                    <input type="date" name="bulk_period_end" class="form-control" value="<?php echo date('Y-m-t'); ?>" required>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="submit" name="bulk_generate" class="btn btn-success">
                                        <i class="fas fa-magic me-2"></i> Generate for Selected Employees
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="selectAllEmployees()">
                                        <i class="fas fa-check-double me-1"></i> Select All
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="deselectAllEmployees()">
                                        <i class="fas fa-times me-1"></i> Clear
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <label class="form-label fw-semibold">Select Employees:</label>
                                <div class="row">
                                    <?php 
                                    $employees->data_seek(0);
                                    while ($emp = $employees->fetch_assoc()): 
                                    ?>
                                        <div class="col-md-4 col-lg-3 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input employee-checkbox" type="checkbox" name="selected_employees[]" value="<?php echo $emp['userID']; ?>" id="emp_<?php echo $emp['userID']; ?>">
                                                <label class="form-check-label" for="emp_<?php echo $emp['userID']; ?>">
                                                    <?php echo htmlspecialchars($emp['Firstname'] . ' ' . $emp['Lastname']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- View All Generated Payslips Section -->
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold mb-0"><i class="fas fa-history me-2"></i> All Generated Payslips</h5>
                            <span class="badge bg-primary"><?php echo $allPayrolls->num_rows; ?> Total</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($allPayrolls->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4">Employee Name</th>
                                            <th>Email</th>
                                            <th>Pay Period</th>
                                            <th class="text-end">Gross Pay</th>
                                            <th class="text-end">Net Pay</th>
                                            <th>Status</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Reset pointer and fetch all
                                        $allPayrolls->data_seek(0);
                                        while ($payroll = $allPayrolls->fetch_assoc()): 
                                        ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <strong><?php echo htmlspecialchars($payroll['Firstname'] . ' ' . $payroll['Lastname']); ?></strong>
                                                </td>
                                                <td class="small text-muted"><?php echo htmlspecialchars($payroll['Email']); ?></td>
                                                <td>
                                                    <span class="badge bg-light text-dark">
                                                        <?php echo date('M d', strtotime($payroll['period_start'])); ?> - 
                                                        <?php echo date('M d, Y', strtotime($payroll['period_end'])); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">₱<?php echo number_format($payroll['gross_pay'], 2); ?></td>
                                                <td class="text-end fw-bold text-success">₱<?php echo number_format($payroll['net_pay'], 2); ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = 'bg-secondary';
                                                    if ($payroll['status'] == 'Processed') $statusClass = 'bg-success';
                                                    elseif ($payroll['status'] == 'Paid') $statusClass = 'bg-primary';
                                                    elseif ($payroll['status'] == 'Pending') $statusClass = 'bg-warning text-dark';
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?> px-2 py-1"><?php echo $payroll['status']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-primary" 
                                                                onclick="viewEmployeePayslips(<?php echo $payroll['userID']; ?>, '<?php echo htmlspecialchars($payroll['Firstname'] . ' ' . $payroll['Lastname']); ?>')">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <?php if ($payroll['status'] != 'Paid'): ?>
                                                            <a href="generate_payslip.php?mark_paid=<?php echo $payroll['payrollID']; ?>" 
                                                               class="btn btn-outline-success" 
                                                               onclick="return confirm('Mark this payslip as Paid?')">
                                                                <i class="fas fa-check"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No payslips generated yet.</p>
                                <p class="small text-muted">Generate payslips using the form above.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payslip Preview -->
        <?php if ($payslipHTML): ?>
        <div class="row justify-content-center mt-4">
            <div class="col-lg-10">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold mb-0">
                                <i class="fas fa-eye me-2"></i> 
                                Payslip Preview: <?php echo htmlspecialchars($selectedPayroll['Firstname'] . ' ' . $selectedPayroll['Lastname']); ?>
                            </h5>
                            <div>
                                <button onclick="printPayslip()" class="btn btn-success">
                                    <i class="fas fa-print me-2"></i> Print
                                </button>
                                <a href="generate_payslip.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Close
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div id="payslipPreview">
                            <?php echo $payslipHTML; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Employee Payslips Modal -->
    <div class="modal fade" id="employeePayslipsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-invoice-dollar me-2"></i> 
                        <span id="modalEmployeeName"></span> - Payslip History
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Pay Period</th>
                                    <th class="text-end">Total Hours</th>
                                    <th class="text-end">Gross Pay</th>
                                    <th class="text-end">Net Pay</th>
                                    <th>Status</th>
                                    <th class="text-center pe-4">Action</th>
                                </tr>
                            </thead>
                            <tbody id="modalPayslipList">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Store all payrolls data for modal
        const allPayrollsData = <?php 
            $allPayrolls->data_seek(0);
            $payrollsArray = [];
            while ($row = $allPayrolls->fetch_assoc()) {
                $payrollsArray[] = $row;
            }
            echo json_encode($payrollsArray);
        ?>;

        // Function to view all payslips for an employee
        function viewEmployeePayslips(userID, employeeName) {
            // Set employee name in modal
            document.getElementById('modalEmployeeName').textContent = employeeName;
            
            // Filter payslips for this employee
            const employeePayslips = allPayrollsData.filter(p => parseInt(p.userID) === userID);
            
            // Sort by period_end descending (newest first)
            employeePayslips.sort((a, b) => new Date(b.period_end) - new Date(a.period_end));
            
            // Populate modal table
            const tbody = document.getElementById('modalPayslipList');
            tbody.innerHTML = '';
            
            if (employeePayslips.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No payslips found for this employee.</td></tr>';
            } else {
                employeePayslips.forEach(payslip => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="ps-4">
                            <span class="badge bg-light text-dark">
                                ${new Date(payslip.period_start).toLocaleDateString('en-US', {month: 'short', day: 'numeric'})} - 
                                ${new Date(payslip.period_end).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}
                            </span>
                        </td>
                        <td class="text-end">${parseFloat(payslip.total_hours).toFixed(2)} hrs</td>
                        <td class="text-end">₱${parseFloat(payslip.gross_pay).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td class="text-end fw-bold text-success">₱${parseFloat(payslip.net_pay).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td>
                            <span class="badge ${payslip.status === 'Processed' ? 'bg-success' : payslip.status === 'Paid' ? 'bg-primary' : 'bg-warning text-dark'} px-2 py-1">
                                ${payslip.status}
                            </span>
                        </td>
                        <td class="text-center pe-4">
                            <div class="btn-group btn-group-sm">
                                <a href="generate_payslip.php?view_payslip=${payslip.payrollID}" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                ${payslip.status !== 'Paid' ? `
                                    <a href="generate_payslip.php?mark_paid=${payslip.payrollID}" class="btn btn-success" onclick="return confirm('Mark this payslip as Paid?')">
                                        <i class="fas fa-check"></i>
                                    </a>
                                ` : ''}
                            </div>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            }
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('employeePayslipsModal'));
            modal.show();
        }

        // Print Payslip Function
        function printPayslip() {
            const preview = document.getElementById('payslipPreview');
            if (preview) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(preview.innerHTML);
                printWindow.document.close();
                printWindow.focus();
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            }
        }

        // Select all employees
        function selectAllEmployees() {
            document.querySelectorAll('.employee-checkbox').forEach(checkbox => {
                checkbox.checked = true;
            });
        }

        // Deselect all employees
        function deselectAllEmployees() {
            document.querySelectorAll('.employee-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        // Print Payslip Function
        function printPayslip() {
            const preview = document.getElementById('payslipPreview');
            if (preview) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(preview.innerHTML);
                printWindow.document.close();
                printWindow.focus();
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            }
        }
    </script>

    <style>
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 260px; background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; }
        .main-content { margin-left: 260px; padding: 30px; }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .nav-link { color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; font-weight: 500; border-radius: 12px; margin: 4px 10px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: #f0f7ff; color: #0077B6; }
        @media (max-width: 991.98px) { .main-content { margin-left: 0; } .sidebar { transform: translateX(-100%); } .sidebar.show { transform: translateX(0); } }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
    </script>
</body>
</html>