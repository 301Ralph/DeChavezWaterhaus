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

// Handle status update
if (isset($_GET['update_status'])) {
    $attendanceID = intval($_GET['update_status']);
    $newStatus    = $conn->real_escape_string($_GET['status']);
    $conn->query("UPDATE attendance SET status = '$newStatus' WHERE attendanceID = $attendanceID");
    echo '<script>window.location = "attendance_management.php";</script>';
    exit();
}

// Handle edit attendance
if (isset($_POST['edit_attendance'])) {
    $attendanceID = intval($_POST['attendanceID']);
    $newClockIn   = $_POST['clock_in'];
    $newClockOut  = $_POST['clock_out'] ?: null;

    $oldStmt = $conn->prepare("SELECT clock_in, clock_out, total_hours FROM attendance WHERE attendanceID = ?");
    $oldStmt->bind_param("i", $attendanceID);
    $oldStmt->execute();
    $oldData = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();

    $totalHours = null;
    if ($newClockOut) {
        $ci = new DateTime($newClockIn); $co = new DateTime($newClockOut);
        $h  = $ci->diff($co)->h + ($ci->diff($co)->i / 60);
        if ($h > 10) $h = 10;
        $totalHours = max(0, round($h - 1.5, 2));
    }

    if ($newClockOut) {
        $stmt = $conn->prepare("UPDATE attendance SET clock_in=?, clock_out=?, total_hours=? WHERE attendanceID=?");
        $stmt->bind_param("ssdi", $newClockIn, $newClockOut, $totalHours, $attendanceID);
    } else {
        $stmt = $conn->prepare("UPDATE attendance SET clock_in=?, clock_out=NULL, total_hours=NULL WHERE attendanceID=?");
        $stmt->bind_param("si", $newClockIn, $attendanceID);
    }

    if ($stmt->execute()) {
        // Audit log (silent fail if table doesn't exist)
        try {
            $logStmt = $conn->prepare("INSERT INTO attendance_audit_log (attendanceID, changed_by, old_clock_in, new_clock_in, old_clock_out, new_clock_out, old_total_hours, new_total_hours, change_reason) VALUES (?,?,?,?,?,?,?,?,?)");
            $reason  = "Admin edit via attendance_management.php";
            $logStmt->bind_param("iissssdds", $attendanceID, $adminID, $oldData['clock_in'], $newClockIn, $oldData['clock_out'], $newClockOut, $oldData['total_hours'], $totalHours, $reason);
            $logStmt->execute(); $logStmt->close();
        } catch (Exception $e) {}
        echo '<script>alert("Attendance updated successfully!"); window.location = "attendance_management.php";</script>';
    } else {
        echo '<script>alert("Error updating attendance."); window.location = "attendance_management.php";</script>';
    }
    $stmt->close(); exit();
}

// Data
$today = date('Y-m-d');

$employees = $conn->query("SELECT userID, Firstname, Lastname, Email, profile_picture, hourly_rate, daily_rate, shift_start_time FROM customers WHERE Role = 'employee' ORDER BY Firstname");

$todayAttendance = $conn->query("
    SELECT a.*, c.Firstname, c.Lastname, c.profile_picture, c.shift_start_time
    FROM attendance a
    JOIN customers c ON a.userID = c.userID
    WHERE DATE(a.clock_in) = '$today'
    ORDER BY a.clock_in DESC
");

$summary = $conn->query("
    SELECT
        COUNT(DISTINCT userID) as total_employees,
        SUM(CASE WHEN DATE(clock_in)=CURDATE() AND clock_out IS NULL THEN 1 ELSE 0 END) as on_duty,
        SUM(CASE WHEN DATE(clock_in)=CURDATE() AND status='Late'      THEN 1 ELSE 0 END) as late_today,
        SUM(CASE WHEN DATE(clock_in)=CURDATE() AND status='Completed' THEN 1 ELSE 0 END) as completed_today
    FROM attendance
    WHERE DATE(clock_in) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch_assoc();

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $adminID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management • Admin</title>
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
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 10px 12px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
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
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px; }
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
        .stat-card { background: linear-gradient(145deg,rgba(10,45,74,0.65),rgba(3,15,30,0.85)); border: 1px solid var(--glass-border); border-radius: 16px; padding: 22px; display: flex; align-items: center; gap: 16px; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); box-shadow: 0 16px 40px rgba(0,0,0,0.3); }
        .stat-icon { width: 50px; height: 50px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12); color: var(--aqua); }
        .si-green  { background: rgba(74,222,128,0.1); color: var(--green); }
        .si-gold   { background: rgba(244,200,66,0.1); color: var(--gold); }
        .si-violet { background: rgba(167,139,250,0.1); color: var(--violet); }
        .stat-num { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── DATA CARDS ── */
        .data-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; margin-bottom: 22px; }
        .data-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 10px; }
        .data-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.18rem; font-weight: 500; color: var(--white); }
        .data-card-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); margin-top: 2px; }

        /* ── TABLE ── */
        .att-table { width: 100%; border-collapse: collapse; }
        .att-table th { font-size: 0.66rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 18px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .att-table td { padding: 14px 18px; font-size: 0.86rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .att-table tr:last-child td { border-bottom: none; }
        .att-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        /* emp avatar inline */
        .emp-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border); flex-shrink: 0; }
        .emp-initial { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .emp-name { font-weight: 500; color: var(--white); font-size: 0.88rem; }
        .emp-sub  { font-size: 0.73rem; color: rgba(202,240,248,0.35); margin-top: 1px; }

        /* status pills */
        .s-pill { padding: 4px 12px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; }
        .s-On-Duty   { background: rgba(0,180,216,0.1);   color: var(--aqua); border: 1px solid rgba(0,180,216,0.25); }
        .s-Completed { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .s-Late      { background: rgba(244,200,66,0.12); color: var(--gold);  border: 1px solid rgba(244,200,66,0.25); }
        .s-Absent    { background: rgba(248,113,113,0.1); color: var(--red);   border: 1px solid rgba(248,113,113,0.25); }
        .s-None      { background: var(--glass); color: rgba(202,240,248,0.4); border: 1px solid var(--glass-border); }

        .on-duty-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--aqua); display: inline-block; margin-right: 6px; animation: blink 1.5s ease-in-out infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }

        .hours-val { font-family: 'Cormorant Garamond', serif; font-size: 1rem; font-weight: 600; color: var(--green); }

        /* select override */
        .status-select { background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); border-radius: 8px; padding: 5px 10px; font-size: 0.78rem; font-family: 'DM Sans', sans-serif; outline: none; cursor: pointer; }
        .status-select:focus { border-color: var(--aqua); }
        .status-select option { background: var(--ocean); }

        /* action buttons */
        .btn-edit { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-edit:hover { background: rgba(0,180,216,0.15); border-color: rgba(0,180,216,0.35); }
        .btn-view { display: inline-flex; align-items: center; gap: 5px; background: rgba(0,119,182,0.15); border: 1px solid rgba(0,180,216,0.22); color: var(--aqua); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; text-decoration: none; transition: all 0.25s; }
        .btn-view:hover { background: rgba(0,180,216,0.2); }

        /* count badge */
        .count-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* empty state */
        .empty-att { text-align: center; padding: 56px 20px; color: rgba(202,240,248,0.3); }
        .empty-att i { font-size: 2.2rem; display: block; margin-bottom: 12px; color: rgba(0,180,216,0.15); }
        .empty-att p { font-size: 0.85rem; }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 18px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 20px 24px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 16px 24px !important; }
        .modal-body { padding: 24px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.3rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-input { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 14px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-hint { font-size: 0.72rem; color: rgba(202,240,248,0.3); margin-top: 5px; }
        .btn-glass-modal { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 9px 18px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-save-modal { padding: 10px 24px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.08em; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.28); }
        .btn-save-modal:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }

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

        @media (max-width: 576px) { .main-content { padding: 14px 12px; } }
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
        <a href="attendance_management.php" class="nav-link active"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payroll_management.php"    class="nav-link"><i class="fas fa-money-bill"></i> Payroll</a>
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
                <h4>Attendance Management</h4>
                <p>Monitor employee attendance and working hours · <?php echo date('l, F j, Y');?></p>
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

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-num"><?php echo $summary['total_employees']??0;?></div>
                    <div class="stat-lbl">Total Employees</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-circle-dot"></i></div>
                <div>
                    <div class="stat-num" style="color:var(--green);"><?php echo $summary['on_duty']??0;?></div>
                    <div class="stat-lbl">On Duty Now</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-clock"></i></div>
                <div>
                    <div class="stat-num" style="color:var(--gold);"><?php echo $summary['late_today']??0;?></div>
                    <div class="stat-lbl">Late Today</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-violet"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div class="stat-num" style="color:var(--violet);"><?php echo $summary['completed_today']??0;?></div>
                    <div class="stat-lbl">Completed Today</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Attendance -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">Today's Attendance</div>
                <div class="data-card-sub"><?php echo date('F j, Y');?></div>
            </div>
            <span class="count-badge"><?php echo $todayAttendance->num_rows;?> Records</span>
        </div>

        <?php if($todayAttendance->num_rows > 0): ?>
        <div style="overflow-x:auto;">
            <table class="att-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th>Paid Hours</th>
                        <th>Status</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $todayAttendance->data_seek(0);
                    while($rec = $todayAttendance->fetch_assoc()):
                        $statusKey = str_replace(' ', '-', $rec['status']);
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <?php if(!empty($rec['profile_picture'])&&file_exists('../'.$rec['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($rec['profile_picture']);?>" class="emp-avatar" alt="">
                                <?php else: ?>
                                    <div class="emp-initial"><?php echo strtoupper(substr($rec['Firstname'],0,1));?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="emp-name"><?php echo htmlspecialchars($rec['Firstname'].' '.$rec['Lastname']);?></div>
                                    <div class="emp-sub">Shift <?php echo date('g:i A', strtotime($rec['shift_start_time']??'07:00:00'));?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-weight:500;color:var(--white);"><?php echo date('g:i A', strtotime($rec['clock_in']));?></td>
                        <td>
                            <?php if($rec['clock_out']): ?>
                                <span style="font-weight:500;color:var(--white);"><?php echo date('g:i A', strtotime($rec['clock_out']));?></span>
                            <?php else: ?>
                                <span><span class="on-duty-dot"></span><span style="color:var(--aqua);font-size:0.78rem;">On Duty</span></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($rec['total_hours']): ?>
                                <span class="hours-val"><?php echo number_format($rec['total_hours'],1);?> hrs</span>
                            <?php else: ?>
                                <span style="color:rgba(202,240,248,0.25);">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="s-pill s-<?php echo $statusKey;?>"><?php echo $rec['status'];?></span></td>
                        <td style="text-align:right;padding-right:18px;">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;">
                                <button class="btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $rec['attendanceID'];?>">
                                    <i class="fas fa-pen"></i> Edit
                                </button>
                                <?php if($rec['clock_out']): ?>
                                    <select class="status-select" onchange="updateStatus(<?php echo $rec['attendanceID'];?>, this.value)">
                                        <option value="Completed" <?php echo $rec['status']=='Completed'?'selected':'';?>>Completed</option>
                                        <option value="Late"      <?php echo $rec['status']=='Late'?'selected':'';?>>Late</option>
                                        <option value="Absent"    <?php echo $rec['status']=='Absent'?'selected':'';?>>Absent</option>
                                    </select>
                                <?php else: ?>
                                    <span style="font-size:0.75rem;color:rgba(202,240,248,0.3);">Active</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-att">
            <i class="fas fa-clock"></i>
            <p>No attendance records for today yet.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- All Employees Status -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">All Employees — Current Status</div>
                <div class="data-card-sub">Real-time roster overview</div>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="att-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Shift</th>
                        <th>Rate</th>
                        <th>Today</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $employees->data_seek(0);
                    while($emp = $employees->fetch_assoc()):
                        $empToday = $conn->query("SELECT * FROM attendance WHERE userID={$emp['userID']} AND DATE(clock_in)='$today' ORDER BY clock_in DESC LIMIT 1")->fetch_assoc();
                        $eStatus  = $empToday ? $empToday['status'] : 'None';
                        $eKey     = str_replace(' ', '-', $eStatus);
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <?php if(!empty($emp['profile_picture'])&&file_exists('../'.$emp['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($emp['profile_picture']);?>" class="emp-avatar" alt="">
                                <?php else: ?>
                                    <div class="emp-initial"><?php echo strtoupper(substr($emp['Firstname'],0,1));?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="emp-name"><?php echo htmlspecialchars($emp['Firstname'].' '.$emp['Lastname']);?></div>
                                    <div class="emp-sub"><?php echo htmlspecialchars($emp['Email']);?></div>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--foam);font-size:0.85rem;">
                            <?php echo date('g:i A', strtotime($emp['shift_start_time']??'07:00:00'));?> – 5:00 PM
                        </td>
                        <td>
                            <div style="font-size:0.85rem;color:var(--foam);">₱<?php echo number_format($emp['hourly_rate']??100,0);?>/hr</div>
                            <div style="font-size:0.72rem;color:rgba(202,240,248,0.35);">₱<?php echo number_format($emp['daily_rate']??800,0);?>/day</div>
                        </td>
                        <td>
                            <?php if($empToday): ?>
                                <span class="s-pill s-<?php echo $eKey;?>"><?php echo $eStatus;?></span>
                            <?php else: ?>
                                <span class="s-pill s-None">Not Clocked In</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;padding-right:18px;">
                            <a href="attendance_management.php?view_employee=<?php echo $emp['userID'];?>" class="btn-view">
                                <i class="fas fa-clock"></i> History
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ── EDIT MODALS ── -->
<?php
$todayAttendance->data_seek(0);
while($rec = $todayAttendance->fetch_assoc()):
?>
<div class="modal fade" id="editModal<?php echo $rec['attendanceID'];?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-pen me-2" style="color:var(--aqua);"></i>
                        Edit · <?php echo htmlspecialchars($rec['Firstname'].' '.$rec['Lastname']);?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="attendanceID" value="<?php echo $rec['attendanceID'];?>">

                    <div style="margin-bottom:18px;">
                        <label class="field-label">Clock-In Time</label>
                        <input type="datetime-local" class="field-input" name="clock_in"
                               value="<?php echo date('Y-m-d\TH:i', strtotime($rec['clock_in']));?>" required>
                    </div>

                    <div>
                        <label class="field-label">Clock-Out Time</label>
                        <input type="datetime-local" class="field-input" name="clock_out"
                               value="<?php echo $rec['clock_out'] ? date('Y-m-d\TH:i', strtotime($rec['clock_out'])) : '';?>">
                        <div class="field-hint">Leave empty if still on duty · 1.5 hr break auto-deducted</div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_attendance" class="btn-save-modal">
                        <i class="fas fa-check me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

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

    // ── STATUS UPDATE ──
    function updateStatus(id, status) {
        window.location = 'attendance_management.php?update_status=' + id + '&status=' + status;
    }
</script>
</body>
</html>