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

// Handle approve / reject
if (isset($_GET['approve'])) {
    $uid = intval($_GET['approve']);
    $conn->query("UPDATE customers SET verification_status='approved' WHERE userID=$uid");
    echo '<script>window.location="manage_users.php";</script>'; exit();
}

if (isset($_GET['reject'])) {
    $uid = intval($_GET['reject']);
    $conn->query("UPDATE customers SET verification_status='rejected' WHERE userID=$uid");
    echo '<script>window.location="manage_users.php";</script>'; exit();
}

$customers = $conn->query("SELECT * FROM customers WHERE Role='customer' ORDER BY created_at DESC");

// Build array + stats
$allUsers   = [];
$pending    = 0; $approved = 0; $rejected = 0;
while($c = $customers->fetch_assoc()) {
    $allUsers[] = $c;
    if($c['verification_status']==='pending')  $pending++;
    if($c['verification_status']==='approved') $approved++;
    if($c['verification_status']==='rejected') $rejected++;
}
$total = count($allUsers);

$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users • Admin</title>
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
        .stat-card { background: linear-gradient(145deg,rgba(10,45,74,0.65),rgba(3,15,30,0.85)); border: 1px solid var(--glass-border); border-radius: 15px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-4px); border-color: rgba(0,180,216,0.25); }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12); color: var(--aqua); }
        .si-green  { background: rgba(74,222,128,0.1);  color: var(--green); }
        .si-gold   { background: rgba(244,200,66,0.1);  color: var(--gold); }
        .si-red    { background: rgba(248,113,113,0.1); color: var(--red); }
        .stat-num  { font-family: 'Cormorant Garamond', serif; font-size: 1.85rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl  { font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── DATA CARD ── */
        .data-card { background: linear-gradient(145deg,rgba(10,45,74,0.5),rgba(3,15,30,0.75)); border: 1px solid var(--glass-border); border-radius: 17px; overflow: hidden; }
        .data-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid var(--glass-border); flex-wrap: wrap; gap: 10px; }
        .data-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.18rem; font-weight: 500; color: var(--white); }
        .data-card-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); margin-top: 2px; }
        .count-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; }

        /* toolbar */
        .toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 14px 20px; border-bottom: 1px solid rgba(72,202,228,0.06); }
        .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-pill { padding: 5px 13px; border-radius: 50px; border: 1px solid var(--glass-border); background: transparent; color: rgba(202,240,248,0.42); font-family: 'DM Sans', sans-serif; font-size: 0.76rem; font-weight: 500; cursor: pointer; transition: all 0.22s; }
        .filter-pill:hover { color: var(--foam); border-color: rgba(0,180,216,0.28); }
        .filter-pill.active { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: transparent; color: var(--deep); font-weight: 700; box-shadow: 0 4px 14px rgba(0,180,216,0.22); }
        .search-wrap { position: relative; }
        .search-input { background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); color: var(--white); border-radius: 50px; padding: 8px 16px 8px 36px; font-size: 0.84rem; font-family: 'DM Sans', sans-serif; outline: none; transition: all 0.3s; width: 240px; }
        .search-input::placeholder { color: rgba(202,240,248,0.22); }
        .search-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.06); }
        .search-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: rgba(0,180,216,0.35); font-size: 0.78rem; }

        /* ── TABLE ── */
        .usr-table { width: 100%; border-collapse: collapse; }
        .usr-table th { font-size: 0.66rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.3); padding: 0 16px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .usr-table td { padding: 14px 16px; font-size: 0.85rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .usr-table tr:last-child td { border-bottom: none; }
        .usr-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        .usr-name { font-weight: 500; color: var(--white); font-size: 0.88rem; }
        .usr-id   { font-size: 0.72rem; color: rgba(202,240,248,0.35); margin-top: 1px; }
        .usr-sub  { font-size: 0.75rem; color: rgba(202,240,248,0.38); }
        .address-txt { font-size: 0.78rem; color: rgba(202,240,248,0.38); max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* status pills */
        .s-pill { display: inline-flex; align-items: center; gap: 4px; padding: 4px 11px; border-radius: 50px; font-size: 0.71rem; font-weight: 700; letter-spacing: 0.06em; }
        .s-approved { background: rgba(74,222,128,0.1);  color: var(--green); border: 1px solid rgba(74,222,128,0.25); }
        .s-pending  { background: rgba(244,200,66,0.12); color: var(--gold);  border: 1px solid rgba(244,200,66,0.25); }
        .s-rejected { background: rgba(248,113,113,0.1); color: var(--red);   border: 1px solid rgba(248,113,113,0.25); }
        .s-email-y  { background: rgba(0,180,216,0.1);   color: var(--aqua);  border: 1px solid rgba(0,180,216,0.25); font-size:0.68rem; }
        .s-email-n  { background: rgba(148,163,184,0.1); color: #94a3b8;       border: 1px solid rgba(148,163,184,0.2); font-size:0.68rem; }

        /* action buttons */
        .btn-view-sm { display: inline-flex; align-items: center; gap: 5px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 6px 14px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-view-sm:hover { background: rgba(0,180,216,0.15); border-color: rgba(0,180,216,0.3); }
        .btn-approve-sm { display: inline-flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.25); color: var(--green); padding: 6px 13px; border-radius: 50px; font-size: 0.76rem; font-weight: 700; text-decoration: none; transition: all 0.25s; }
        .btn-approve-sm:hover { background: rgba(74,222,128,0.2); color: var(--green); }
        .btn-reject-sm { display: inline-flex; align-items: center; gap: 5px; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.22); color: var(--red); padding: 6px 13px; border-radius: 50px; font-size: 0.76rem; font-weight: 700; text-decoration: none; transition: all 0.25s; }
        .btn-reject-sm:hover { background: rgba(248,113,113,0.18); color: var(--red); }

        /* pending badge (pulsing) */
        .pending-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--gold); animation: pd 1.6s ease-in-out infinite; }
        @keyframes pd { 0%,100%{opacity:1} 50%{opacity:0.3} }

        .empty-state { text-align: center; padding: 52px 20px; color: rgba(202,240,248,0.3); }
        .empty-state i { font-size: 2.2rem; display: block; margin-bottom: 12px; color: rgba(0,180,216,0.15); }
        .empty-state p { font-size: 0.85rem; }
        #noResults { display: none; text-align: center; padding: 38px; color: rgba(202,240,248,0.3); font-size: 0.85rem; }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 18px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 20px 24px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 16px 24px !important; }
        .modal-body { padding: 24px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.3rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        /* detail rows in modal */
        .detail-row { padding: 12px 0; border-bottom: 1px solid rgba(72,202,228,0.07); display: flex; gap: 16px; }
        .detail-row:last-child { border-bottom: none; }
        .detail-lbl { font-size: 0.68rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.35); min-width: 130px; flex-shrink: 0; padding-top: 2px; }
        .detail-val { font-size: 0.87rem; color: var(--foam); word-break: break-word; }

        .address-box { background: rgba(4,30,53,0.5); border: 1px solid var(--glass-border); border-radius: 10px; padding: 12px 14px; font-size: 0.85rem; color: rgba(202,240,248,0.65); line-height: 1.6; }

        .btn-glass-modal { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 9px 18px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass-modal:hover { background: rgba(0,180,216,0.15); color: var(--foam); }
        .btn-approve-modal { padding: 9px 22px; background: linear-gradient(135deg, #15803d, #4ade80); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 14px rgba(74,222,128,0.2); text-decoration: none; }
        .btn-approve-modal:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(74,222,128,0.35); color: var(--deep); }
        .btn-reject-modal { padding: 9px 22px; background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.28); color: var(--red); border-radius: 50px; font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-reject-modal:hover { background: rgba(248,113,113,0.2); }

        /* proof modal image */
        .proof-img { max-width: 100%; max-height: 480px; border-radius: 12px; border: 1px solid var(--glass-border); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 38px; height: 38px; border-radius: 9px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.88rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 18px 16px; }
            .mobile-toggle { display: flex; }
            .search-input { width: 180px; }
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
        <a href="manage_users.php"      class="nav-link active"><i class="fas fa-users"></i> Users</a>
        <a href="manage_employees.php"  class="nav-link"><i class="fas fa-user-tie"></i> Employees</a>
        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
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
                <h4>Manage Users</h4>
                <p>View and verify all registered customers</p>
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
                <div class="stat-icon si-blue"><i class="fas fa-users"></i></div>
                <div><div class="stat-num"><?php echo $total;?></div><div class="stat-lbl">Total Users</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-user-check"></i></div>
                <div><div class="stat-num" style="color:var(--green);"><?php echo $approved;?></div><div class="stat-lbl">Verified</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-user-clock"></i></div>
                <div><div class="stat-num" style="color:var(--gold);"><?php echo $pending;?></div><div class="stat-lbl">Pending</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon si-red"><i class="fas fa-user-xmark"></i></div>
                <div><div class="stat-num" style="color:var(--red);"><?php echo $rejected;?></div><div class="stat-lbl">Rejected</div></div>
            </div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="data-card">
        <div class="data-card-head">
            <div>
                <div class="data-card-title">All Customers</div>
                <div class="data-card-sub">Click "View" for full details or approve pending accounts</div>
            </div>
            <span class="count-badge"><?php echo $total;?> User<?php echo $total!=1?'s':'';?></span>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="filter-pills">
                <button class="filter-pill active" onclick="filterUsers('all',this)">All</button>
                <button class="filter-pill" onclick="filterUsers('approved',this)">Verified</button>
                <button class="filter-pill" onclick="filterUsers('pending',this)">
                    Pending <?php if($pending>0): ?><span style="background:rgba(244,200,66,0.2);color:var(--gold);border-radius:50px;padding:0 6px;font-size:0.68rem;margin-left:2px;"><?php echo $pending;?></span><?php endif; ?>
                </button>
                <button class="filter-pill" onclick="filterUsers('rejected',this)">Rejected</button>
            </div>
            <div class="search-wrap">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" id="userSearch" placeholder="Search users…">
            </div>
        </div>

        <?php if(count($allUsers) > 0): ?>
        <div style="overflow-x:auto;">
            <table class="usr-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Account</th>
                        <th>Email Verified</th>
                        <th>Joined</th>
                        <th style="text-align:right;padding-right:22px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersBody">
                    <?php foreach($allUsers as $cust): ?>
                    <tr class="usr-row"
                        data-status="<?php echo $cust['verification_status'];?>"
                        data-search="<?php echo strtolower(htmlspecialchars($cust['Firstname'].' '.$cust['Lastname'].' '.$cust['Email'].' '.($cust['Contact']??'')));?>">
                        <td>
                            <div class="usr-name"><?php echo htmlspecialchars($cust['Firstname'].' '.$cust['Lastname']);?></div>
                            <div class="usr-id">#<?php echo str_pad($cust['userID'],5,'0',STR_PAD_LEFT);?></div>
                        </td>
                        <td class="usr-sub"><?php echo htmlspecialchars($cust['Email']);?></td>
                        <td class="usr-sub"><?php echo htmlspecialchars($cust['Contact']??'—');?></td>
                        <td><div class="address-txt"><?php echo htmlspecialchars($cust['Address']??'—');?></div></td>
                        <td>
                            <?php if($cust['verification_status']==='approved'): ?>
                                <span class="s-pill s-approved"><i class="fas fa-check" style="font-size:0.6rem;"></i> Verified</span>
                            <?php elseif($cust['verification_status']==='pending'): ?>
                                <span class="s-pill s-pending"><span class="pending-dot"></span> Pending</span>
                            <?php else: ?>
                                <span class="s-pill s-rejected"><i class="fas fa-times" style="font-size:0.6rem;"></i> Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if(!empty($cust['email_verified']) && $cust['email_verified']): ?>
                                <span class="s-pill s-email-y"><i class="fas fa-envelope" style="font-size:0.6rem;"></i> Yes</span>
                            <?php else: ?>
                                <span class="s-pill s-email-n"><i class="fas fa-envelope" style="font-size:0.6rem;"></i> No</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.76rem;color:rgba(202,240,248,0.32);"><?php echo date('M j, Y', strtotime($cust['created_at']));?></td>
                        <td style="text-align:right;padding-right:18px;">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;">
                                <?php if($cust['verification_status']==='pending'): ?>
                                    <?php if(!empty($cust['VerificationFile'])): ?>
                                    <button class="btn-view-sm" data-bs-toggle="modal" data-bs-target="#proofModal<?php echo $cust['userID'];?>">
                                        <i class="fas fa-file-alt"></i> ID
                                    </button>
                                    <?php endif; ?>
                                    <a href="manage_users.php?approve=<?php echo $cust['userID'];?>" class="btn-approve-sm" onclick="return confirm('Approve this account?')">
                                        <i class="fas fa-check"></i> Approve
                                    </a>
                                    <a href="manage_users.php?reject=<?php echo $cust['userID'];?>" class="btn-reject-sm" onclick="return confirm('Reject this account?')">
                                        <i class="fas fa-times"></i> Reject
                                    </a>
                                <?php else: ?>
                                    <button class="btn-view-sm" data-bs-toggle="modal" data-bs-target="#userModal<?php echo $cust['userID'];?>">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults">No users match your search or filter.</div>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-users"></i>
            <p>No customers registered yet.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ── VIEW USER MODALS ── -->
<?php foreach($allUsers as $cust): ?>
<div class="modal fade" id="userModal<?php echo $cust['userID'];?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user me-2" style="color:var(--aqua);font-size:0.9rem;"></i>
                    <?php echo htmlspecialchars($cust['Firstname'].' '.$cust['Lastname']);?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-0">
                    <div class="col-md-6">
                        <div class="detail-row">
                            <div class="detail-lbl">Full Name</div>
                            <div class="detail-val"><?php echo htmlspecialchars($cust['Firstname'].' '.$cust['Lastname']);?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-lbl">Email</div>
                            <div class="detail-val"><?php echo htmlspecialchars($cust['Email']);?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-lbl">Phone</div>
                            <div class="detail-val"><?php echo htmlspecialchars($cust['Contact']??'—');?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-lbl">Account Status</div>
                            <div class="detail-val">
                                <?php if($cust['verification_status']==='approved'): ?>
                                    <span class="s-pill s-approved"><i class="fas fa-check" style="font-size:0.6rem;"></i> Verified</span>
                                <?php elseif($cust['verification_status']==='pending'): ?>
                                    <span class="s-pill s-pending">Pending</span>
                                <?php else: ?>
                                    <span class="s-pill s-rejected">Rejected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-row">
                            <div class="detail-lbl">Joined</div>
                            <div class="detail-val"><?php echo date('F j, Y g:i A', strtotime($cust['created_at']));?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-lbl">Email Verified</div>
                            <div class="detail-val">
                                <?php if(!empty($cust['email_verified'])&&$cust['email_verified']): ?>
                                    <span class="s-pill s-email-y">Yes</span>
                                <?php else: ?>
                                    <span class="s-pill s-email-n">No</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-lbl">2FA</div>
                            <div class="detail-val"><?php echo !empty($cust['two_factor_enabled'])&&$cust['two_factor_enabled'] ? '<span class="s-pill s-approved">Enabled</span>' : '<span class="s-pill s-email-n">Disabled</span>';?></div>
                        </div>
                        <?php if(!empty($cust['VerificationFile'])): ?>
                        <div class="detail-row">
                            <div class="detail-lbl">Verification File</div>
                            <div class="detail-val">
                                <?php if(file_exists('../'.$cust['VerificationFile'])): ?>
                                    <a href="../<?php echo htmlspecialchars($cust['VerificationFile']);?>" target="_blank" class="btn-view-sm" style="font-size:0.75rem;">
                                        <i class="fas fa-external-link-alt"></i> View File
                                    </a>
                                <?php else: ?>
                                    <span style="color:rgba(202,240,248,0.3);font-size:0.8rem;">File not found</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if(!empty($cust['Address'])): ?>
                <div style="margin-top:16px;">
                    <div style="font-size:0.68rem;letter-spacing:0.12em;text-transform:uppercase;color:rgba(202,240,248,0.35);margin-bottom:8px;">Delivery Address</div>
                    <div class="address-box"><?php echo htmlspecialchars($cust['Address']);?></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer d-flex gap-2 justify-content-end">
                <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Close</button>
                <?php if($cust['verification_status']==='pending'): ?>
                    <a href="manage_users.php?approve=<?php echo $cust['userID'];?>" class="btn-approve-modal" onclick="return confirm('Approve this account?')"><i class="fas fa-check me-1"></i>Approve</a>
                    <a href="manage_users.php?reject=<?php echo $cust['userID'];?>" class="btn-reject-modal" onclick="return confirm('Reject this account?')"><i class="fas fa-times me-1"></i>Reject</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ── PROOF MODALS (pending with verification file) ── -->
<?php foreach($allUsers as $cust):
    if($cust['verification_status']!=='pending' || empty($cust['VerificationFile'])) continue;
    $filePath = '../'.$cust['VerificationFile'];
    $fileExt  = strtolower(pathinfo($cust['VerificationFile'], PATHINFO_EXTENSION));
?>
<div class="modal fade" id="proofModal<?php echo $cust['userID'];?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-id-card me-2" style="color:var(--gold);font-size:0.9rem;"></i>
                    ID Verification · <?php echo htmlspecialchars($cust['Firstname'].' '.$cust['Lastname']);?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <?php if(file_exists($filePath)): ?>
                    <?php if(in_array($fileExt,['jpg','jpeg','png','webp'])): ?>
                        <img src="<?php echo htmlspecialchars($filePath);?>" class="proof-img" alt="ID Proof">
                    <?php elseif($fileExt==='pdf'): ?>
                        <div style="background:rgba(0,180,216,0.07);border:1px solid rgba(0,180,216,0.18);border-radius:14px;padding:28px;">
                            <i class="fas fa-file-pdf" style="font-size:2.5rem;color:var(--red);display:block;margin-bottom:12px;"></i>
                            <p style="color:rgba(202,240,248,0.5);font-size:0.86rem;margin-bottom:14px;">PDF Document</p>
                            <a href="<?php echo htmlspecialchars($filePath);?>" target="_blank" class="btn-approve-modal">
                                <i class="fas fa-external-link-alt me-1"></i> Open PDF
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="background:rgba(244,200,66,0.07);border:1px solid rgba(244,200,66,0.2);border-radius:12px;padding:20px;color:var(--gold);">
                            <i class="fas fa-exclamation-triangle me-2"></i>Unsupported file format
                        </div>
                    <?php endif; ?>
                    <div style="margin-top:14px;">
                        <a href="<?php echo htmlspecialchars($filePath);?>" download class="btn-view-sm">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                <?php else: ?>
                    <div style="background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.22);border-radius:12px;padding:20px;color:var(--red);">
                        <i class="fas fa-exclamation-circle me-2"></i>File not found
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer d-flex gap-2 justify-content-end">
                <button type="button" class="btn-glass-modal" data-bs-dismiss="modal">Close</button>
                <a href="manage_users.php?approve=<?php echo $cust['userID'];?>" class="btn-approve-modal" onclick="return confirm('Approve this verification?')">
                    <i class="fas fa-check me-1"></i> Approve
                </a>
                <a href="manage_users.php?reject=<?php echo $cust['userID'];?>" class="btn-reject-modal" onclick="return confirm('Reject this verification?')">
                    <i class="fas fa-times me-1"></i> Reject
                </a>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

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

    // ── FILTER + SEARCH ──
    let currentFilter = 'all';
    let currentSearch = '';

    function filterUsers(val, btn) {
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilter = val;
        applyFilter();
    }

    function applyFilter() {
        const rows = document.querySelectorAll('.usr-row');
        let vis = 0;
        rows.forEach(row => {
            const matchFilter = currentFilter === 'all' || row.dataset.status === currentFilter;
            const matchSearch = !currentSearch || row.dataset.search.includes(currentSearch);
            const show = matchFilter && matchSearch;
            row.style.display = show ? '' : 'none';
            if(show) vis++;
        });
        const nr = document.getElementById('noResults');
        if(nr) nr.style.display = vis === 0 ? 'block' : 'none';
    }

    document.getElementById('userSearch')?.addEventListener('input', function() {
        currentSearch = this.value.toLowerCase().trim();
        applyFilter();
    });
</script>
</body>
</html>