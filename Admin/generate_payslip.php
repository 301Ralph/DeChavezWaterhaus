<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data for profile picture
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $_SESSION['userID'])->fetch_assoc();

// Fetch all employees
$employees = $conn->query("SELECT userID, Firstname, Lastname, Email FROM customers WHERE Role = 'employee' ORDER BY Firstname");

// Handle payslip generation
if (isset($_POST['generate_payslip'])) {
    $userID = intval($_POST['userID']);
    $periodStart = $_POST['period_start'];
    $periodEnd = $_POST['period_end'];
    
    // Get payroll record
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
        // Generate HTML payslip (printable)
        $html = generatePayslipHTML($payroll);
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="Payslip_' . $payroll['Firstname'] . '_' . date('M_Y', strtotime($periodEnd)) . '.html"');
        echo $html;
        exit();
    } else {
        echo '<script>alert("No payroll record found for the selected period."); window.location = "generate_payslip.php";</script>';
    }
    exit();
}

function generatePayslipHTML($payroll) {
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
        <tr><th>Pay Period</th><td>' . date('F d, Y', strtotime($payroll['period_start'])) . ' — ' . date('F d, Y', strtotime($payroll['period_end'])) . '</td></tr>
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
        <button onclick="window.print()" class="print-btn">🖨️ Print / Save as PDF</button>
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
        :root { --primary: #0077B6; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
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

        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-4">
                        <div class="text-center">
                            <i class="fas fa-file-pdf fa-3x text-primary mb-3"></i>
                            <h4 class="fw-bold">Generate Payslip</h4>
                            <p class="text-muted mb-0">Create official payslips for employees</p>
                        </div>
                    </div>
                    
                    <div class="card-body p-4">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Select Employee</label>
                                <select name="userID" class="form-select" required>
                                    <option value="">Choose employee...</option>
                                    <?php while ($emp = $employees->fetch_assoc()): ?>
                                        <option value="<?php echo $emp['userID']; ?>">
                                            <?php echo htmlspecialchars($emp['Firstname'] . ' ' . $emp['Lastname']); ?> 
                                            (<?php echo $emp['Email']; ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Period Start</label>
                                    <input type="date" name="period_start" class="form-control" value="<?php echo date('Y-m-01'); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Period End</label>
                                    <input type="date" name="period_end" class="form-control" value="<?php echo date('Y-m-t'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" name="generate_payslip" class="btn btn-primary btn-lg">
                                    <i class="fas fa-file-pdf me-2"></i> Generate & Download Payslip
                                </button>
                                <a href="payroll_management.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Payroll
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

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