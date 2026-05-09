<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$userID   = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch employee data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch ALL payslips
$payslipsStmt = $conn->prepare("SELECT * FROM payroll WHERE userID = ? ORDER BY created_at DESC");
$payslipsStmt->bind_param("i", $userID);
$payslipsStmt->execute();
$payslipsResult = $payslipsStmt->get_result();
$payslips = [];
while ($row = $payslipsResult->fetch_assoc()) $payslips[] = $row;
$payslipsStmt->close();

$payroll = !empty($payslips) ? $payslips[0] : null;

// Flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType    = $_SESSION['flash_type']    ?? 'info';
if ($flashMessage) { unset($_SESSION['flash_message'], $_SESSION['flash_type']); }

if (!$payroll) {
    $_SESSION['flash_message'] = "No payroll record found. Please contact admin.";
    $_SESSION['flash_type']    = "error";
    header("Location: employee_dashboard.php");
    exit();
}

// Get the payslip to display
$displayPayroll = $payroll;
if (isset($_GET['view'])) {
    foreach ($payslips as $p) {
        if ($p['payrollID'] == $_GET['view']) { $displayPayroll = $p; break; }
    }
}

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payslip • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --red: #f87171;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 24px 22px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); box-shadow: 0 0 14px rgba(0,180,216,0.2); }
        .sidebar-logo-text { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-logo-sub { font-size: 0.68rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 16px 12px 16px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.62rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.25); padding: 16px 12px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 10px; color: rgba(202,240,248,0.5) !important; text-decoration: none; font-size: 0.87rem; font-weight: 500; transition: all 0.25s ease; margin-bottom: 2px; position: relative; }
        .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; color: rgba(0,180,216,0.4); transition: color 0.25s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .sidebar-footer { padding: 14px 12px; border-top: 1px solid var(--glass-border); flex-shrink: 0; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 28px 32px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .topbar-left h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-left p { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-btn { width: 42px; height: 42px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; text-decoration: none; transition: all 0.3s; position: relative; cursor: pointer; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.58rem; font-weight: 700; min-width: 16px; height: 16px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 4px; }
        .avatar-btn { display: flex; align-items: center; gap: 10px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 6px 14px 6px 6px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.82rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.7rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 14px !important; padding: 8px !important; box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 8px !important; padding: 9px 14px !important; font-size: 0.84rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── HISTORY PANEL ── */
        .history-panel {
            background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
            height: 100%;
            display: flex; flex-direction: column;
        }

        .history-panel-head {
            padding: 20px 22px;
            border-bottom: 1px solid var(--glass-border);
            display: flex; justify-content: space-between; align-items: center;
        }

        .hp-title { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 500; color: var(--white); }
        .hp-count { font-size: 0.72rem; color: rgba(202,240,248,0.35); }

        .history-list { flex: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.1) transparent; }
        .history-list::-webkit-scrollbar { width: 3px; }
        .history-list::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.1); border-radius: 2px; }

        .history-item {
            display: block;
            padding: 14px 20px;
            border-bottom: 1px solid rgba(72,202,228,0.06);
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }

        .history-item:hover { background: rgba(0,180,216,0.05); }

        .history-item.selected {
            background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08));
            border-left: 3px solid var(--aqua);
        }

        .hi-period { font-size: 0.88rem; font-weight: 500; color: var(--white); margin-bottom: 3px; }
        .history-item.selected .hi-period { color: var(--aqua); }
        .hi-dates  { font-size: 0.73rem; color: rgba(202,240,248,0.38); }
        .hi-net    { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 600; color: var(--green); }

        .hi-status { padding: 2px 9px; border-radius: 50px; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.06em; }
        .hs-Paid    { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .hs-Pending { background: rgba(244,200,66,0.1);  color: var(--gold);  border: 1px solid rgba(244,200,66,0.25); }

        /* ── PAYSLIP CARD ── */
        .payslip-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.5), rgba(3,15,30,0.78));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
        }

        /* payslip header */
        .ps-head {
            background: linear-gradient(135deg, rgba(0,119,182,0.4), rgba(0,180,216,0.2));
            border-bottom: 1px solid rgba(0,180,216,0.2);
            padding: 30px 32px;
            position: relative;
            overflow: hidden;
        }

        .ps-head::before { content: ''; position: absolute; top: -60px; right: -60px; width: 180px; height: 180px; border-radius: 50%; background: rgba(0,180,216,0.08); }
        .ps-head::after  { content: ''; position: absolute; bottom: -40px; left: 40px; width: 100px; height: 100px; border-radius: 50%; background: rgba(0,119,182,0.08); }

        .ps-company-name { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 500; color: var(--white); letter-spacing: 0.05em; position: relative; z-index: 1; }
        .ps-company-sub  { font-size: 0.72rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-top: 2px; position: relative; z-index: 1; }
        .ps-period       { font-size: 0.82rem; color: rgba(202,240,248,0.5); margin-top: 8px; position: relative; z-index: 1; }

        .ps-net-badge {
            background: rgba(74,222,128,0.15);
            border: 1px solid rgba(74,222,128,0.3);
            border-radius: 14px;
            padding: 12px 20px;
            text-align: center;
            position: relative; z-index: 1;
        }

        .ps-net-label { font-size: 0.68rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(74,222,128,0.6); margin-bottom: 4px; }
        .ps-net-value { font-family: 'Cormorant Garamond', serif; font-size: 2.2rem; font-weight: 600; color: var(--green); line-height: 1; }

        /* employee info bar */
        .ps-employee-bar {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 20px 28px;
            border-bottom: 1px solid rgba(72,202,228,0.08);
            flex-wrap: wrap; gap: 16px;
        }

        .ps-emp-label { font-size: 0.68rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.3); margin-bottom: 4px; }
        .ps-emp-value { font-size: 0.92rem; color: var(--white); font-weight: 500; }

        /* breakdown */
        .ps-breakdown { padding: 24px 28px; }

        .ps-section-label {
            font-size: 0.68rem; letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--aqua); margin-bottom: 14px;
            display: flex; align-items: center; gap: 10px;
        }

        .ps-section-label::after { content: ''; flex: 1; height: 1px; background: rgba(0,180,216,0.15); }

        .ps-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 11px 0;
            border-bottom: 1px solid rgba(72,202,228,0.06);
        }

        .ps-row:last-child { border-bottom: none; }

        .ps-row-label { font-size: 0.87rem; color: rgba(202,240,248,0.6); }
        .ps-row-value { font-size: 0.9rem; font-weight: 500; color: var(--foam); }
        .ps-row-value.positive { color: var(--green); }
        .ps-row-value.negative { color: var(--red); }
        .ps-row-value.highlight { font-family: 'Cormorant Garamond', serif; font-size: 1.2rem; font-weight: 600; color: var(--aqua); }

        /* divider before total */
        .ps-total-divider { border: none; border-top: 1px solid rgba(72,202,228,0.18); margin: 8px 0; }

        .ps-total-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 0 0;
        }

        .ps-total-label { font-family: 'Cormorant Garamond', serif; font-size: 1.2rem; font-weight: 500; color: var(--white); }
        .ps-total-value { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 600; color: var(--green); }

        /* status chip */
        .ps-status-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em;
        }

        .ps-footer {
            padding: 16px 28px;
            border-top: 1px solid rgba(72,202,228,0.08);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }

        .ps-generated { font-size: 0.73rem; color: rgba(202,240,248,0.28); }

        /* action buttons */
        .btn-print {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 24px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none; border-radius: 50px;
            color: var(--deep); font-family: 'DM Sans', sans-serif;
            font-size: 0.82rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
            cursor: pointer; transition: all 0.3s;
            box-shadow: 0 5px 16px rgba(0,180,216,0.25);
        }

        .btn-print:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }

        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px;
            background: var(--glass); border: 1px solid var(--glass-border);
            color: var(--aqua); border-radius: 50px;
            font-size: 0.82rem; font-weight: 600;
            text-decoration: none; transition: all 0.3s;
        }

        .btn-back:hover { background: rgba(0,180,216,0.15); color: var(--foam); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .ps-head { padding: 22px 20px; }
            .ps-breakdown, .ps-employee-bar { padding: 18px 20px; }
            .ps-footer { padding: 14px 20px; }
        }

        /* ── PRINT STYLES ── */
        @media print {
            .sidebar, .sidebar-overlay, .topbar, .history-panel, .mobile-toggle,
            .btn-print, .btn-back, .ps-footer { display: none !important; }

            .main-content { margin-left: 0 !important; padding: 0 !important; background: white !important; }
            body { background: white !important; color: #1a1a1a !important; }

            .payslip-card {
                background: white !important;
                border: 2px solid #0077b6 !important;
                border-radius: 12px !important;
                box-shadow: none !important;
            }

            .ps-head {
                background: #0077b6 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .ps-company-name, .ps-company-sub, .ps-period, .ps-employee-bar,
            .ps-row-label, .ps-row-value, .ps-total-label { color: #1a1a1a !important; }

            .ps-net-value, .ps-total-value { color: #15803d !important; }
            .ps-row-value.negative { color: #dc2626 !important; }
            .ps-section-label { color: #0077b6 !important; }
            .ps-generated { display: block !important; color: #666 !important; }
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
            <div class="sidebar-logo-sub">Employee Portal</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="employee_dashboard.php" class="nav-link"><i class="fas fa-house"></i> Dashboard</a>
        <a href="attendance.php"         class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payslip.php"            class="nav-link active"><i class="fas fa-file-invoice-dollar"></i> My Payslip</a>
        <a href="leave_request.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Leave Requests</a>
        <a href="my_deliveries.php"      class="nav-link"><i class="fas fa-truck"></i> My Deliveries</a>
        <a href="profile.php"            class="nav-link"><i class="fas fa-user"></i> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <h4>My Payslip</h4>
                <p>View and print your payslip history</p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="dropdown">
                <button class="topbar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:280px;max-height:340px;overflow-y:auto;">
                    <li style="padding:12px 16px 8px;font-size:0.7rem;letter-spacing:0.15em;text-transform:uppercase;color:rgba(202,240,248,0.3);">Notifications</li>
                    <?php
                    $notifs = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 5");
                    if($notifs->num_rows > 0):
                        while($n = $notifs->fetch_assoc()):
                    ?>
                        <li><a class="dropdown-item" href="notifications.php" style="font-size:0.83rem;white-space:normal;"><?php echo htmlspecialchars(mb_strimwidth($n['message'],0,70,'…'));?></a></li>
                    <?php endwhile; else: ?>
                        <li><span class="dropdown-item" style="color:rgba(202,240,248,0.35);font-size:0.83rem;">No notifications</span></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="notifications.php" style="text-align:center;font-size:0.8rem;color:var(--aqua);">View All</a></li>
                </ul>
            </div>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($employee['profile_picture'])&&file_exists('../'.$employee['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($employee['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName);?></div>
                        <div class="avatar-role">Employee</div>
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

    <!-- Flash -->
    <?php if($flashMessage): ?>
    <div style="background:rgba(4,30,53,0.85);border:1px solid <?php echo $flashType==='success'?'rgba(74,222,128,0.3)':'rgba(248,113,113,0.3)';?>;border-radius:12px;padding:12px 18px;margin-bottom:20px;font-size:0.88rem;color:<?php echo $flashType==='success'?'#4ade80':'#fca5a5';?>;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-<?php echo $flashType==='success'?'check-circle':'exclamation-circle';?>"></i>
        <?php echo htmlspecialchars($flashMessage);?>
    </div>
    <?php endif; ?>

    <div class="row g-4" id="payslipView">

        <!-- History Sidebar -->
        <div class="col-lg-4">
            <div class="history-panel">
                <div class="history-panel-head">
                    <div class="hp-title">Payslip History</div>
                    <div class="hp-count"><?php echo count($payslips);?> record<?php echo count($payslips)!=1?'s':'';?></div>
                </div>

                <div class="history-list">
                    <?php foreach($payslips as $i => $p):
                        $isSelected = isset($_GET['view'])
                            ? $_GET['view'] == $p['payrollID']
                            : $i === 0;
                        $statusClass = $p['status'] === 'Paid' ? 'hs-Paid' : 'hs-Pending';
                    ?>
                    <a href="?view=<?php echo $p['payrollID'];?>" class="history-item <?php echo $isSelected?'selected':'';?>">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                            <div>
                                <div class="hi-period"><?php echo date('F Y', strtotime($p['period_end']));?></div>
                                <div class="hi-dates">
                                    <?php echo date('M d', strtotime($p['period_start']));?> – <?php echo date('M d, Y', strtotime($p['period_end']));?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div class="hi-net">₱<?php echo number_format($p['net_pay'],0);?></div>
                                <span class="hi-status <?php echo $statusClass;?>"><?php echo $p['status'];?></span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Payslip Detail -->
        <div class="col-lg-8" id="payslipDetail">
            <div class="payslip-card">

                <!-- Header -->
                <div class="ps-head">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="d-flex align-items-center gap-3 mb-2" style="position:relative;z-index:1;">
                                <img src="../images/logo.jpg" alt=""
                                     style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);">
                                <div>
                                    <div class="ps-company-name">De Chavez Waterhaus</div>
                                    <div class="ps-company-sub">Official Payslip</div>
                                </div>
                            </div>
                            <div class="ps-period" style="position:relative;z-index:1;">
                                Pay Period: <?php echo date('F d, Y', strtotime($displayPayroll['period_start']));?> –
                                <?php echo date('F d, Y', strtotime($displayPayroll['period_end']));?>
                            </div>
                        </div>
                        <div class="col-auto" style="position:relative;z-index:1;">
                            <div class="ps-net-badge">
                                <div class="ps-net-label">Net Pay</div>
                                <div class="ps-net-value">₱<?php echo number_format($displayPayroll['net_pay'],2);?></div>
                                <?php
                                $sc = $displayPayroll['status'] === 'Paid' ? 'hs-Paid' : 'hs-Pending';
                                ?>
                                <div class="mt-2 text-center">
                                    <span class="hi-status <?php echo $sc;?>"><?php echo $displayPayroll['status'];?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Employee Info -->
                <div class="ps-employee-bar">
                    <div>
                        <div class="ps-emp-label">Employee Name</div>
                        <div class="ps-emp-value"><?php echo htmlspecialchars($employee['Firstname'].' '.$employee['Lastname']);?></div>
                    </div>
                    <div>
                        <div class="ps-emp-label">Employee ID</div>
                        <div class="ps-emp-value">#<?php echo str_pad($userID, 5, '0', STR_PAD_LEFT);?></div>
                    </div>
                    <div>
                        <div class="ps-emp-label">Payslip ID</div>
                        <div class="ps-emp-value">#<?php echo str_pad($displayPayroll['payrollID'], 6, '0', STR_PAD_LEFT);?></div>
                    </div>
                    <div>
                        <div class="ps-emp-label">Pay Date</div>
                        <div class="ps-emp-value"><?php echo date('M d, Y', strtotime($displayPayroll['created_at']));?></div>
                    </div>
                </div>

                <!-- Breakdown -->
                <div class="ps-breakdown">

                    <!-- Earnings -->
                    <div class="ps-section-label">Earnings</div>

                    <div class="ps-row">
                        <span class="ps-row-label"><i class="fas fa-clock me-2" style="color:rgba(0,180,216,0.4);font-size:0.8rem;"></i>Total Hours Worked</span>
                        <span class="ps-row-value"><?php echo number_format($displayPayroll['total_hours'],2);?> hrs</span>
                    </div>
                    <div class="ps-row">
                        <span class="ps-row-label"><i class="fas fa-peso-sign me-2" style="color:rgba(0,180,216,0.4);font-size:0.8rem;"></i>Hourly Rate</span>
                        <span class="ps-row-value">₱<?php echo number_format($displayPayroll['hourly_rate'],2);?> / hr</span>
                    </div>
                    <div class="ps-row">
                        <span class="ps-row-label" style="font-weight:600;color:var(--foam);">Gross Pay</span>
                        <span class="ps-row-value positive" style="font-weight:700;">₱<?php echo number_format($displayPayroll['gross_pay'],2);?></span>
                    </div>

                    <!-- Deductions -->
                    <div class="ps-section-label" style="margin-top:18px;">Deductions</div>

                    <div class="ps-row">
                        <span class="ps-row-label"><i class="fas fa-minus-circle me-2" style="color:rgba(248,113,113,0.5);font-size:0.8rem;"></i>SSS / PhilHealth / Pag-IBIG</span>
                        <span class="ps-row-value negative">−₱<?php echo number_format($displayPayroll['deductions'],2);?></span>
                    </div>

                    <?php if(!empty($displayPayroll['notes'])): ?>
                    <!-- Notes -->
                    <div class="ps-section-label" style="margin-top:18px;">Notes</div>
                    <div style="font-size:0.84rem;color:rgba(202,240,248,0.5);padding:10px 0;">
                        <?php echo htmlspecialchars($displayPayroll['notes']);?>
                    </div>
                    <?php endif; ?>

                    <!-- Total -->
                    <hr class="ps-total-divider" style="margin-top:18px;">
                    <div class="ps-total-row">
                        <span class="ps-total-label">NET PAY</span>
                        <span class="ps-total-value">₱<?php echo number_format($displayPayroll['net_pay'],2);?></span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="ps-footer">
                    <div class="ps-generated">
                        Generated: <?php echo date('F d, Y', strtotime($displayPayroll['created_at']));?> ·
                        This is a computer-generated document.
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="employee_dashboard.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Dashboard
                        </a>
                        <button class="btn-print" onclick="window.print()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

            </div>
        </div>
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

    // ── AUTO PRINT ──
    <?php if(isset($_GET['print']) && $_GET['print'] == '1'): ?>
    window.addEventListener('load', () => window.print());
    <?php endif; ?>
</script>
</body>
</html>