<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID   = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';
$admin     = $conn->query("SELECT * FROM customers WHERE userID = $adminID")->fetch_assoc();

// Handle mark as paid
if (isset($_GET['mark_paid'])) {
    $payrollID = intval($_GET['mark_paid']);
    $conn->query("UPDATE payroll SET status='Paid', payment_date=CURDATE() WHERE payrollID=$payrollID");
    echo '<script>window.location="payroll_management.php";</script>'; exit();
}

// Handle payroll processing
if (isset($_POST['process_payroll'])) {
    $uid         = intval($_POST['userID']);
    $periodStart = $_POST['period_start'];
    $periodEnd   = $_POST['period_end'];

    // Total hours from attendance
    $hs = $conn->prepare("SELECT SUM(total_hours) as t FROM attendance WHERE userID=? AND DATE(clock_in) BETWEEN ? AND ?");
    $hs->bind_param("iss", $uid, $periodStart, $periodEnd); $hs->execute();
    $totalHours = $hs->get_result()->fetch_assoc()['t'] ?? 0; $hs->close();

    // Employee rate
    $es = $conn->prepare("SELECT hourly_rate, daily_rate FROM customers WHERE userID=?");
    $es->bind_param("i", $uid); $es->execute();
    $ed = $es->get_result()->fetch_assoc(); $es->close();
    $hourlyRate = $ed['hourly_rate'] ?? 100;
    $dailyRate  = $ed['daily_rate']  ?? 800;

    $grossPay   = $totalHours * $hourlyRate;
    $deductions = $grossPay * 0.10;
    $netPay     = $grossPay - $deductions;

    // Check if payroll already exists
    $ck = $conn->prepare("SELECT payrollID FROM payroll WHERE userID=? AND period_start=? AND period_end=?");
    $ck->bind_param("iss", $uid, $periodStart, $periodEnd); $ck->execute();
    $exists = $ck->get_result()->num_rows > 0; $ck->close();

    if ($exists) {
        echo '<script>alert("Payroll already processed for this period!"); window.location="payroll_management.php";</script>';
    } else {
        $ins = $conn->prepare("INSERT INTO payroll (userID,period_start,period_end,total_hours,hourly_rate,daily_rate,gross_pay,deductions,net_pay,status) VALUES (?,?,?,?,?,?,?,?,?,'Processed')");
        $ins->bind_param("issddddddd", $uid, $periodStart, $periodEnd, $totalHours, $hourlyRate, $dailyRate, $grossPay, $deductions, $netPay);
        echo $ins->execute()
            ? '<script>alert("Payroll processed! Net Pay: ₱'.number_format($netPay,2).'"); window.location="payroll_management.php";</script>'
            : '<script>alert("Error processing payroll."); window.location="payroll_management.php";</script>';
        $ins->close();
    }
    exit();
}

// Fetch payroll records
$payrollRecords = $conn->query("
    SELECT p.*, c.Firstname, c.Lastname, c.profile_picture
    FROM payroll p
    JOIN customers c ON p.userID = c.userID
    ORDER BY p.created_at DESC
    LIMIT 60
");

$employees = $conn->query("SELECT userID, Firstname, Lastname, hourly_rate, daily_rate FROM customers WHERE Role='employee' ORDER BY Firstname");

// Stats
$statsQ  = $conn->query("SELECT COUNT(*) as total, SUM(net_pay) as total_paid, SUM(CASE WHEN status='Paid' THEN net_pay ELSE 0 END) as paid_sum, SUM(CASE WHEN status='Processed' THEN net_pay ELSE 0 END) as unpaid_sum FROM payroll");
$stats   = $statsQ->fetch_assoc();

$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --red: #f87171;     --violet: #a78bfa;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); }
        .sidebar-logo-text { font-family: 'Cormorant Garamond', serif; font-size: 1rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-logo-sub  { font-size: 0.65rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 10px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 3px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.58rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.22); padding: 14px 10px 5px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 9px; color: rgba(202,240,248,0.48) !important; text-decoration: none; font-size: 0.84rem; font-weight: 500; transition: all 0.22s ease; margin-bottom: 1px; position: relative; }
        .nav-link i { width: 16px; text-align: center; font-size: 0.85rem; color: rgba(0,180,216,0.38); transition: color 0.22s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 22%; bottom: 22%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .sidebar-footer { padding: 12px 10px; border-top: 1px solid var(--glass-border); flex-shrink: 0; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 26px 30px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .topbar-left h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.65rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-left p { font-size: 0.8rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .topbar-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.88rem; text-decoration: none; transition: all 0.3s; position: relative; cursor: pointer; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.55rem; font-weight: 700; min-width: 15px; height: 15px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 3px; }
        .avatar-btn { display: flex; align-items: center; gap: 9px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 5px 12px 5px 5px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.8rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.68rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 13px !important; padding: 7px !important; box-shadow: 0 18px 48px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 7px !important; padding: 8px 13px !important; font-size: 0.83rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── STAT CARDS ── */
        .stat-card { background: linear-gradient(145deg,rgba(10,45,74,0.65),rgba(3,15,30,0.85)); border: 1px solid var(--glass-border); border-radius: 16px; padding: 20px 22px; display: flex; align-items: center; gap: 16px; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); box-shadow: 0 16px 40px rgba(0,0,0,0.3); }
        .stat-icon { width: 48px; height: 48px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12); color: var(--aqua); }
        .si-green  { background: rgba(74,222,128,0.1);  color: var(--green); }
        .si-gold   { background: rgba(244,200,66,0.1);  color: var(--gold); }
        .si-violet { background: rgba(167,139,250,0.1); color: var(--violet); }
        .stat-num  { font-family: 'Cormorant Garamond', serif; font-size: 1.85rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-num-sm { font-family: 'Cormorant Garamond', serif; font-size: 1.35rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl  { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── PROCESS FORM CARD ── */
        .form-card { background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; margin-bottom: 22px; }
        .form-card-head { padding: 18px 22px; border-bottom: 1px solid var(--glass-border); display: flex; align-items: center; gap: 10px; }
        .form-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.15rem; font-weight: 500; color: var(--white); }
        .form-card-body  { padding: 22px; }

        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-input, .field-select { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 14px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-input:focus, .field-select:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }
        .field-hint { font-size: 0.72rem; color: rgba(202,240,248,0.28); margin-top: 5px; }

        .btn-process { display: inline-flex; align-items: center; gap: 7px; padding: 12px 26px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.84rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); width: 100%; justify-content: center; }
        .btn-process:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }

        /* ── DATA CARD ── */
        .data-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; }
        .data-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 10px; }
        .data-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.18rem; font-weight: 500; color: var(--white); }
        .data-card-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); margin-top: 2px; }
        .count-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* filter pills */
        .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; padding: 14px 20px; border-bottom: 1px solid rgba(72,202,228,0.06); }
        .filter-pill { padding: 5px 13px; border-radius: 50px; border: 1px solid var(--glass-border); background: transparent; color: rgba(202,240,248,0.42); font-family: 'DM Sans', sans-serif; font-size: 0.76rem; font-weight: 500; cursor: pointer; transition: all 0.22s; }
        .filter-pill:hover { color: var(--foam); border-color: rgba(0,180,216,0.28); }
        .filter-pill.active { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: transparent; color: var(--deep); font-weight: 700; box-shadow: 0 4px 14px rgba(0,180,216,0.22); }

        /* ── TABLE ── */
        .pay-table { width: 100%; border-collapse: collapse; }
        .pay-table th { font-size: 0.66rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 16px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .pay-table td { padding: 14px 16px; font-size: 0.85rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .pay-table tr:last-child td { border-bottom: none; }
        .pay-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        .emp-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border); flex-shrink: 0; }
        .emp-initial { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .emp-name  { font-weight: 500; color: var(--white); font-size: 0.88rem; }

        .period-badge { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 3px 10px; font-size: 0.73rem; color: rgba(202,240,248,0.5); }
        .hours-val { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 600; color: var(--white); }
        .rate-val  { font-size: 0.78rem; color: rgba(202,240,248,0.45); }
        .gross-val { font-weight: 500; color: var(--foam); }
        .deduct-val { color: var(--red); }
        .net-val   { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 600; color: var(--green); }

        /* status pills */
        .s-Processed { background: rgba(0,180,216,0.1);   color: var(--aqua);  border: 1px solid rgba(0,180,216,0.25); padding: 4px 11px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; }
        .s-Paid      { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); padding: 4px 11px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; }

        /* mark paid button */
        .btn-mark-paid { display: inline-flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.25); color: var(--green); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 700; cursor: pointer; transition: all 0.25s; }
        .btn-mark-paid:hover { background: rgba(74,222,128,0.2); transform: translateY(-1px); }
        .paid-date { font-size: 0.74rem; color: rgba(202,240,248,0.3); }

        /* empty */
        .empty-state { text-align: center; padding: 52px 20px; color: rgba(202,240,248,0.3); }
        .empty-state i { font-size: 2.2rem; display: block; margin-bottom: 12px; color: rgba(0,180,216,0.15); }
        .empty-state p { font-size: 0.85rem; }

        /* no results */
        #noResults { display: none; text-align: center; padding: 36px; color: rgba(202,240,248,0.3); font-size: 0.85rem; }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 38px; height: 38px; border-radius: 9px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.88rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 18px 16px; }
            .mobile-toggle { display: flex; }
        }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <div>
            <div class="sidebar-logo-text">De Chavez Waterhaus</div>
            <div class="sidebar-logo-sub">Admin Panel</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php"   class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="manage_products.php"   class="nav-link"><i class="fas fa-box"></i> Products</a>
        <a href="manage_orders.php"     class="nav-link"><i class="fas fa-shopping-cart"></i> Orders</a>
        <a href="manage_users.php"      class="nav-link"><i class="fas fa-users"></i> Users</a>
        <a href="manage_employees.php"  class="nav-link"><i class="fas fa-user-tie"></i> Employees</a>
        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payroll_management.php"    class="nav-link active"><i class="fas fa-money-bill"></i> Payroll</a>
        <a href="generate_payslip.php"      class="nav-link"><i class="fas fa-file-pdf"></i> Generate Payslip</a>
        <a href="leave_management.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Manage Leave</a>
        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php"   class="nav-link"><i class="fas fa-headset"></i> Support Tickets</a>
        <a href="reports.php"           class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="nav-section-label" style="margin-top:14px;"></div>
        <a href="profile.php"           class="nav-link"><i class="fas fa-user"></i> My Profile</a>
        <a href="../logout.php"         class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <h4>Payroll Management</h4>
                <p>Process and manage employee payroll records</p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
            </a>
            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($admin['profile_picture'])&&file_exists('../'.$admin['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($admin['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($adminName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($adminName);?></div>
                        <div class="avatar-role">Administrator</div>
                    </div>
                    <i class="fas fa-chevron-down fa-xs ms-1" style="color:rgba(202,240,248,0.3);"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-file-invoice-dollar"></i></div>
                <div><div class="stat-num"><?php echo $stats['total'];?></div><div class="stat-lbl">Total Records</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
                <div>
                    <div class="stat-num-sm" style="color:var(--green);">₱<?php echo number_format($stats['paid_sum']??0,0);?></div>
                    <div class="stat-lbl">Total Paid Out</div>
                </div>
            </div>
        </div>
        <div class="stat-card col-6 col-md-3" style="border-radius:16px;padding:20px 22px;display:flex;align-items:center;gap:16px;">
            <div class="stat-icon si-gold"><i class="fas fa-clock-rotate-left"></i></div>
            <div>
                <div class="stat-num-sm" style="color:var(--gold);">₱<?php echo number_format($stats['unpaid_sum']??0,0);?></div>
                <div class="stat-lbl">Unpaid Balance</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-violet"><i class="fas fa-peso-sign"></i></div>
                <div>
                    <div class="stat-num-sm" style="color:var(--violet);">₱<?php echo number_format($stats['total_paid']??0,0);?></div>
                    <div class="stat-lbl">All-Time Total</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Process Payroll Form -->
    <div class="form-card">
        <div class="form-card-head">
            <i class="fas fa-calculator" style="color:var(--aqua);font-size:0.95rem;"></i>
            <div class="form-card-title">Process New Payroll</div>
        </div>
        <div class="form-card-body">
            <form method="POST">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="field-label">Employee</label>
                        <select name="userID" class="field-select" required>
                            <option value="">Select employee…</option>
                            <?php while($emp = $employees->fetch_assoc()): ?>
                            <option value="<?php echo $emp['userID'];?>">
                                <?php echo htmlspecialchars($emp['Firstname'].' '.$emp['Lastname']);?> · ₱<?php echo number_format($emp['hourly_rate']??100,0);?>/hr
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="field-label">Period Start</label>
                        <input type="date" name="period_start" class="field-input" value="<?php echo date('Y-m-01');?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="field-label">Period End</label>
                        <input type="date" name="period_end" class="field-input" value="<?php echo date('Y-m-t');?>" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="process_payroll" class="btn-process">
                            <i class="fas fa-calculator"></i> Process
                        </button>
                    </div>
                </div>
                <div class="field-hint" style="margin-top:12px;">
                    <i class="fas fa-info-circle me-1" style="color:var(--aqua);"></i>
                    Auto-calculates gross pay from attendance records · 10% statutory deductions applied
                </div>
            </form>
        </div>
    </div>

    <!-- Payroll Records Table -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">Payroll History</div>
                <div class="data-card-sub">Last 60 records</div>
            </div>
            <span class="count-badge"><?php echo $payrollRecords->num_rows;?> Records</span>
        </div>

        <!-- Filter Pills -->
        <div class="filter-pills">
            <button class="filter-pill active" onclick="filterPayroll('all',this)">All</button>
            <button class="filter-pill" onclick="filterPayroll('Processed',this)">Processed (Unpaid)</button>
            <button class="filter-pill" onclick="filterPayroll('Paid',this)">Paid</button>
        </div>

        <?php if($payrollRecords->num_rows > 0): ?>
        <div style="overflow-x:auto;">
            <table class="pay-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Period</th>
                        <th>Hours</th>
                        <th>Rate</th>
                        <th>Gross</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                        <th>Status</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="payrollBody">
                    <?php $payrollRecords->data_seek(0); while($pr = $payrollRecords->fetch_assoc()): ?>
                    <tr class="pay-row" data-status="<?php echo $pr['status'];?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <?php if(!empty($pr['profile_picture'])&&file_exists('../'.$pr['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($pr['profile_picture']);?>" class="emp-avatar" alt="">
                                <?php else: ?>
                                    <div class="emp-initial"><?php echo strtoupper(substr($pr['Firstname'],0,1));?></div>
                                <?php endif; ?>
                                <div class="emp-name"><?php echo htmlspecialchars($pr['Firstname'].' '.$pr['Lastname']);?></div>
                            </div>
                        </td>
                        <td>
                            <span class="period-badge">
                                <i class="fas fa-calendar-days" style="font-size:0.65rem;"></i>
                                <?php echo date('M j', strtotime($pr['period_start']));?> – <?php echo date('M j, Y', strtotime($pr['period_end']));?>
                            </span>
                        </td>
                        <td><span class="hours-val"><?php echo number_format($pr['total_hours'],1);?></span><span class="rate-val"> hrs</span></td>
                        <td><span class="rate-val">₱<?php echo number_format($pr['hourly_rate'],0);?>/hr</span></td>
                        <td><span class="gross-val">₱<?php echo number_format($pr['gross_pay'],2);?></span></td>
                        <td><span class="deduct-val">−₱<?php echo number_format($pr['deductions'],2);?></span></td>
                        <td><span class="net-val">₱<?php echo number_format($pr['net_pay'],2);?></span></td>
                        <td><span class="s-<?php echo $pr['status'];?>"><?php echo $pr['status'];?></span></td>
                        <td style="text-align:right;padding-right:18px;">
                            <?php if($pr['status']==='Processed'): ?>
                                <button class="btn-mark-paid" onclick="markPaid(<?php echo $pr['payrollID'];?>)">
                                    <i class="fas fa-check"></i> Mark Paid
                                </button>
                            <?php else: ?>
                                <span class="paid-date">
                                    <i class="fas fa-check-circle me-1" style="color:var(--green);"></i>
                                    <?php echo date('M j, Y', strtotime($pr['payment_date']??$pr['created_at']));?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults">No payroll records match this filter.</div>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-money-bill"></i>
            <p>No payroll records yet.<br>Process your first payroll using the form above.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── SIDEBAR ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('mobileToggle');
    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
    if(toggle)  toggle.addEventListener('click', openSidebar);
    if(overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth<992) closeSidebar(); }));

    // ── MARK PAID ──
    function markPaid(id) {
        if(confirm('Mark this payroll record as Paid?')) {
            window.location = 'payroll_management.php?mark_paid=' + id;
        }
    }

    // ── FILTER ──
    function filterPayroll(status, btn) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const rows = document.querySelectorAll('.pay-row');
        let vis = 0;

        rows.forEach(row => {
            const show = status === 'all' || row.dataset.status === status;
            row.style.display = show ? '' : 'none';
            if(show) vis++;
        });

        const nr = document.getElementById('noResults');
        if(nr) nr.style.display = vis === 0 ? 'block' : 'none';
    }
</script>
</body>
</html>