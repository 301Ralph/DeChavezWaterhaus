<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID   = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $adminID)->fetch_assoc();

// Stats
$totalOrders    = $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'] ?? 0;
$totalRevenue   = $conn->query("SELECT SUM(total_amount) as total FROM orders WHERE status = 'Delivered'")->fetch_assoc()['total'] ?? 0;
$totalCustomers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE Role = 'customer'")->fetch_assoc()['count'] ?? 0;
$pendingOrders  = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status IN ('Pending', 'Processing')")->fetch_assoc()['count'] ?? 0;

$activeEmployees = 0;
try { $activeEmployees = $conn->query("SELECT COUNT(*) as count FROM customers WHERE Role = 'employee'")->fetch_assoc()['count'] ?? 0; } catch(Exception $e) {}

$openTickets = 0;
try { $openTickets = $conn->query("SELECT COUNT(*) as count FROM support_tickets WHERE status IN ('Open', 'In Progress')")->fetch_assoc()['count'] ?? 0; } catch(Exception $e) {}

$newOrdersToday = 0;
try { $newOrdersToday = $conn->query("SELECT COUNT(*) as count FROM orders WHERE DATE(order_date) = CURDATE()")->fetch_assoc()['count'] ?? 0; } catch(Exception $e) {}

$todayRevenue = 0;
try { $todayRevenue = $conn->query("SELECT SUM(total_amount) as total FROM orders WHERE DATE(order_date) = CURDATE() AND status != 'Cancelled'")->fetch_assoc()['total'] ?? 0; } catch(Exception $e) {}

// Low stock
$lowStockProducts = [];
try {
    $lsr = $conn->query("SELECT ProductName, Stock FROM product WHERE Stock <= 10 ORDER BY Stock ASC LIMIT 5");
    while ($row = $lsr->fetch_assoc()) $lowStockProducts[] = $row;
} catch(Exception $e) {}

// Recent orders
$recentOrders = $conn->query("
    SELECT o.orderID, o.order_date, o.total_amount, o.status, o.payment_method,
           CONCAT(c.Firstname, ' ', c.Lastname) as customer_name
    FROM orders o
    JOIN customers c ON o.userID = c.userID
    ORDER BY o.order_date DESC LIMIT 10
");

// Pending verifications
$pendingVerifications = $conn->query("
    SELECT userID, Firstname, Lastname, Email, created_at
    FROM customers WHERE verification_status = 'pending' AND Role = 'customer'
    ORDER BY created_at DESC LIMIT 5
");

$notifCount  = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $adminID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName   = explode(' ', $adminName)[0];
$hour        = (int)date('H');
$greeting    = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --violet: #a78bfa;  --red: #f87171;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); box-shadow: 0 0 14px rgba(0,180,216,0.2); }
        .sidebar-logo-text { font-family: 'Cormorant Garamond', serif; font-size: 1rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-logo-sub  { font-size: 0.65rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 10px 16px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 3px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.6rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.22); padding: 14px 12px 5px; }
        .nav-link { display: flex; align-items: center; gap: 11px; padding: 10px 13px; border-radius: 9px; color: rgba(202,240,248,0.48) !important; text-decoration: none; font-size: 0.85rem; font-weight: 500; transition: all 0.25s ease; margin-bottom: 2px; position: relative; }
        .nav-link i { width: 16px; text-align: center; font-size: 0.85rem; color: rgba(0,180,216,0.38); transition: color 0.25s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .sidebar-footer { padding: 12px 10px; border-top: 1px solid var(--glass-border); flex-shrink: 0; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 26px 30px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .topbar-greeting h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.65rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-greeting p { font-size: 0.8rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .topbar-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.88rem; text-decoration: none; transition: all 0.3s; position: relative; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.56rem; font-weight: 700; min-width: 15px; height: 15px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 4px; }
        .avatar-btn { display: flex; align-items: center; gap: 10px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 5px 13px 5px 5px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.8rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.68rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 14px !important; padding: 8px !important; box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 8px !important; padding: 9px 14px !important; font-size: 0.84rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── WELCOME BANNER ── */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(0,119,182,0.28), rgba(0,180,216,0.12));
            border: 1px solid rgba(0,180,216,0.22);
            border-radius: 18px; padding: 26px 30px; margin-bottom: 26px;
            position: relative; overflow: hidden;
        }

        .welcome-banner::before { content:''; position:absolute; top:-60px; right:-60px; width:180px; height:180px; border-radius:50%; background:rgba(0,180,216,0.07); }
        .welcome-banner-title { font-family: 'Cormorant Garamond', serif; font-size: 1.75rem; font-weight: 300; color: var(--white); }
        .welcome-banner-sub   { font-size: 0.85rem; color: rgba(202,240,248,0.5); margin-top: 4px; }

        /* alert chips */
        .alert-chip {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 14px; border-radius: 50px; font-size: 0.77rem; font-weight: 600;
            margin-top: 14px; margin-right: 8px; position: relative; z-index: 1;
            text-decoration: none;
        }

        .chip-warning { background: rgba(244,200,66,0.12); border: 1px solid rgba(244,200,66,0.28); color: var(--gold); }
        .chip-success { background: rgba(74,222,128,0.1);  border: 1px solid rgba(74,222,128,0.28); color: var(--green); }
        .chip-info    { background: rgba(0,180,216,0.1);   border: 1px solid rgba(0,180,216,0.25);  color: var(--aqua); }
        .chip-red     { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.25);color: var(--red); }

        .chip-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: cPulse 2s ease-in-out infinite; }
        @keyframes cPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(0.7)} }

        /* ── STAT CARDS ── */
        .stat-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.65), rgba(3,15,30,0.85));
            border: 1px solid var(--glass-border);
            border-radius: 16px; padding: 22px 20px;
            display: flex; align-items: center; gap: 16px;
            transition: all 0.35s cubic-bezier(0.23,1,0.32,1);
            position: relative; overflow: hidden;
        }

        .stat-card::after { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent,rgba(0,180,216,0.3),transparent); opacity:0; transition:opacity 0.3s; }
        .stat-card:hover { transform:translateY(-5px); border-color:rgba(0,180,216,0.28); box-shadow:0 18px 42px rgba(0,0,0,0.32); }
        .stat-card:hover::after { opacity:1; }

        .stat-icon { width: 50px; height: 50px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .si-blue   { background: rgba(0,180,216,0.12);   color: var(--aqua); }
        .si-green  { background: rgba(74,222,128,0.1);   color: var(--green); }
        .si-gold   { background: rgba(244,200,66,0.1);   color: var(--gold); }
        .si-violet { background: rgba(167,139,250,0.1);  color: var(--violet); }
        .si-teal   { background: rgba(0,119,182,0.15);   color: #60a5fa; }
        .si-red    { background: rgba(248,113,113,0.1);  color: var(--red); }

        .stat-num { font-family: 'Cormorant Garamond', serif; font-size: 1.85rem; font-weight: 600; color: var(--white); line-height: 1; }
        .stat-lbl { font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }

        /* ── DASH CARDS ── */
        .dash-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78));
            border: 1px solid var(--glass-border);
            border-radius: 18px; padding: 24px;
        }

        .dash-title { font-family: 'Cormorant Garamond', serif; font-size: 1.2rem; font-weight: 500; color: var(--white); }

        /* ── SECTION LABEL ── */
        .section-label {
            font-size: 0.68rem; letter-spacing: 0.18em; text-transform: uppercase;
            color: rgba(202,240,248,0.28); margin-bottom: 14px;
            display: flex; align-items: center; gap: 10px;
        }

        .section-label::after { content:''; flex:1; height:1px; background:var(--glass-border); }

        /* ── ORDERS TABLE ── */
        .orders-table { width: 100%; border-collapse: collapse; }
        .orders-table th { font-size: 0.67rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.28); padding: 0 14px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .orders-table td { padding: 13px 14px; font-size: 0.85rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .orders-table tr:last-child td { border-bottom: none; }
        .orders-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }
        .order-id-cell { font-family: 'Cormorant Garamond', serif; font-size: 1rem; color: var(--white) !important; font-weight: 500; }
        .order-amount  { font-family: 'Cormorant Garamond', serif; font-size: 1rem; color: var(--aqua) !important; font-weight: 600; }

        /* status pills */
        .s-pill { padding: 4px 11px; border-radius: 50px; font-size: 0.69rem; font-weight: 700; letter-spacing: 0.07em; white-space: nowrap; }
        .s-Pending          { background:rgba(244,200,66,0.12); color:var(--gold);    border:1px solid rgba(244,200,66,0.25); }
        .s-Processing       { background:rgba(0,180,216,0.1);   color:var(--aqua);    border:1px solid rgba(0,180,216,0.25); }
        .s-Out-for-Delivery { background:rgba(96,165,250,0.1);  color:#60a5fa;        border:1px solid rgba(96,165,250,0.25); }
        .s-Delivered        { background:rgba(74,222,128,0.1);  color:var(--green);   border:1px solid rgba(74,222,128,0.25); }
        .s-Cancelled        { background:rgba(248,113,113,0.1); color:var(--red);     border:1px solid rgba(248,113,113,0.25); }

        /* ── QUICK ACTION BUTTONS ── */
        .qa-btn {
            display: flex; align-items: center; gap: 12px;
            padding: 13px 16px; background: rgba(4,30,53,0.55);
            border: 1px solid var(--glass-border); border-radius: 12px;
            text-decoration: none; color: var(--foam); transition: all 0.3s; margin-bottom: 8px;
        }

        .qa-btn:last-child { margin-bottom: 0; }
        .qa-btn:hover { background: var(--glass); border-color: rgba(0,180,216,0.3); color: var(--white); transform: translateX(3px); }
        .qa-icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
        .qa-icon.gold   { background: linear-gradient(135deg, #b45309, var(--gold)); }
        .qa-icon.green  { background: linear-gradient(135deg, #166534, var(--green)); }
        .qa-icon.violet { background: linear-gradient(135deg, #5b21b6, var(--violet)); }
        .qa-label { font-size: 0.86rem; font-weight: 500; }
        .qa-sub   { font-size: 0.72rem; color: rgba(202,240,248,0.38); }
        .qa-arrow { margin-left: auto; color: rgba(0,180,216,0.3); font-size: 0.72rem; transition: all 0.3s; }
        .qa-btn:hover .qa-arrow { color: var(--aqua); transform: translateX(3px); }

        /* ── SYSTEM OVERVIEW ROWS ── */
        .sys-row { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-bottom: 1px solid rgba(72,202,228,0.07); }
        .sys-row:last-child { border-bottom: none; }
        .sys-label { font-size: 0.85rem; color: rgba(202,240,248,0.55); display: flex; align-items: center; gap: 8px; }
        .sys-value { font-family: 'Cormorant Garamond', serif; font-size: 1.2rem; font-weight: 600; color: var(--white); }

        /* ── VERIFICATIONS TABLE ── */
        .verif-table { width: 100%; border-collapse: collapse; }
        .verif-table th { font-size: 0.67rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.28); padding: 0 14px 12px; text-align: left; border-bottom: 1px solid var(--glass-border); }
        .verif-table td { padding: 13px 14px; font-size: 0.85rem; color: rgba(202,240,248,0.7); border-bottom: 1px solid rgba(72,202,228,0.06); vertical-align: middle; }
        .verif-table tr:last-child td { border-bottom: none; }
        .verif-table tr:hover td { background: rgba(0,180,216,0.03); color: var(--foam); }

        /* action btns */
        .btn-approve { display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:50px;font-size:0.75rem;font-weight:700;background:rgba(74,222,128,0.1);border:1px solid rgba(74,222,128,0.25);color:var(--green);text-decoration:none;transition:all 0.25s; }
        .btn-approve:hover { background:rgba(74,222,128,0.2); }
        .btn-reject  { display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:50px;font-size:0.75rem;font-weight:700;background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.22);color:var(--red);text-decoration:none;transition:all 0.25s; }
        .btn-reject:hover  { background:rgba(248,113,113,0.18); }
        .btn-view    { display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:50px;font-size:0.75rem;font-weight:600;background:var(--glass);border:1px solid var(--glass-border);color:var(--aqua);text-decoration:none;transition:all 0.25s; }
        .btn-view:hover { background:rgba(0,180,216,0.15);color:var(--foam); }

        /* ── LOW STOCK BANNER ── */
        .low-stock-banner {
            background: rgba(244,200,66,0.07);
            border: 1px solid rgba(244,200,66,0.22);
            border-radius: 14px; padding: 16px 20px;
            margin-bottom: 22px;
            display: flex; align-items: flex-start; gap: 14px;
        }

        .lsb-icon { color: var(--gold); font-size: 1.1rem; flex-shrink: 0; margin-top: 2px; }
        .lsb-title { font-size: 0.84rem; font-weight: 600; color: var(--gold); margin-bottom: 6px; }
        .lsb-items { display: flex; flex-wrap: wrap; gap: 6px; }
        .lsb-item  { background: rgba(244,200,66,0.1); border: 1px solid rgba(244,200,66,0.2); border-radius: 50px; padding: 3px 11px; font-size: 0.75rem; color: rgba(244,200,66,0.85); }
        .lsb-link  { display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:0.78rem;color:var(--gold);font-weight:600;text-decoration:none; }
        .lsb-link:hover { color: var(--white); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 16px; }
            .mobile-toggle { display: flex; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 14px 12px; }
            .welcome-banner { padding: 20px; }
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
            <div class="sidebar-logo-sub">Admin Portal</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php"   class="nav-link active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="manage_products.php"   class="nav-link"><i class="fas fa-box"></i> Manage Products</a>
        <a href="manage_orders.php"     class="nav-link"><i class="fas fa-shopping-cart"></i> Manage Orders</a>
        <a href="manage_users.php"      class="nav-link"><i class="fas fa-users"></i> Manage Users</a>
        <a href="manage_employees.php"  class="nav-link"><i class="fas fa-user-tie"></i> Manage Employees</a>

        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payroll_management.php"    class="nav-link"><i class="fas fa-money-bill"></i> Payroll</a>
        <a href="generate_payslip.php"      class="nav-link"><i class="fas fa-file-pdf"></i> Generate Payslip</a>
        <a href="leave_management.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Manage Leave</a>

        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php"   class="nav-link"><i class="fas fa-headset"></i> Support Tickets</a>
        <a href="reports.php"           class="nav-link"><i class="fas fa-chart-bar"></i> Reports & Analytics</a>

        <div class="nav-section-label" style="margin-top:14px;"></div>
        <a href="profile.php"           class="nav-link"><i class="fas fa-user"></i> My Profile</a>
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
            <div class="topbar-greeting">
                <h4><?php echo $greeting;?>, <?php echo htmlspecialchars($firstName);?>!</h4>
                <p>Here's what's happening at De Chavez Waterhaus today</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo $notifCount>9?'9+':$notifCount;?></span><?php endif; ?>
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

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="row align-items-center">
            <div class="col">
                <div class="welcome-banner-title">Admin Overview</div>
                <div class="welcome-banner-sub">De Chavez Waterhaus · <?php echo date('l, F j, Y');?></div>
                <div style="margin-top:14px;position:relative;z-index:1;">
                    <?php if($pendingOrders > 0): ?>
                        <a href="manage_orders.php?filter=Pending" class="alert-chip chip-warning">
                            <span class="chip-dot"></span>
                            <?php echo $pendingOrders;?> Pending Orders
                        </a>
                    <?php endif; ?>
                    <?php if($newOrdersToday > 0): ?>
                        <span class="alert-chip chip-success">
                            <i class="fas fa-check" style="font-size:0.65rem;"></i>
                            <?php echo $newOrdersToday;?> New Today
                        </span>
                    <?php endif; ?>
                    <?php if($openTickets > 0): ?>
                        <a href="support_tickets.php" class="alert-chip chip-info">
                            <span class="chip-dot"></span>
                            <?php echo $openTickets;?> Open Tickets
                        </a>
                    <?php endif; ?>
                    <?php if(!empty($lowStockProducts)): ?>
                        <a href="manage_products.php" class="alert-chip chip-red">
                            <span class="chip-dot"></span>
                            <?php echo count($lowStockProducts);?> Low Stock
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-auto d-none d-md-block" style="position:relative;z-index:1;">
                <i class="fas fa-droplet" style="font-size:4rem;color:rgba(0,180,216,0.12);"></i>
            </div>
        </div>
    </div>

    <!-- Low Stock Banner -->
    <?php if(!empty($lowStockProducts)): ?>
    <div class="low-stock-banner">
        <i class="fas fa-triangle-exclamation lsb-icon"></i>
        <div>
            <div class="lsb-title"><i class="fas fa-boxes-stacked me-1"></i> Low Stock Alert</div>
            <div class="lsb-items">
                <?php foreach($lowStockProducts as $p): ?>
                    <span class="lsb-item">
                        <?php echo htmlspecialchars($p['ProductName']);?>
                        <strong style="margin-left:4px;">(<?php echo $p['Stock'];?> left)</strong>
                    </span>
                <?php endforeach; ?>
            </div>
            <a href="manage_products.php" class="lsb-link">
                <i class="fas fa-arrow-right" style="font-size:0.7rem;"></i> Manage Stock Now
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-2 col-md-4">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-shopping-cart"></i></div>
                <div>
                    <div class="stat-num"><?php echo number_format($totalOrders);?></div>
                    <div class="stat-lbl">Total Orders</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-2 col-md-4">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-peso-sign"></i></div>
                <div>
                    <div class="stat-num" style="font-size:1.4rem;">₱<?php echo number_format($totalRevenue,0);?></div>
                    <div class="stat-lbl">Total Revenue</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-2 col-md-4">
            <div class="stat-card">
                <div class="stat-icon si-violet"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-num"><?php echo number_format($totalCustomers);?></div>
                    <div class="stat-lbl">Customers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-2 col-md-4">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-clock"></i></div>
                <div>
                    <div class="stat-num"><?php echo number_format($pendingOrders);?></div>
                    <div class="stat-lbl">Pending</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-2 col-md-4">
            <div class="stat-card">
                <div class="stat-icon si-teal"><i class="fas fa-user-tie"></i></div>
                <div>
                    <div class="stat-num"><?php echo $activeEmployees;?></div>
                    <div class="stat-lbl">Employees</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-2 col-md-4">
            <div class="stat-card">
                <div class="stat-icon si-red"><i class="fas fa-headset"></i></div>
                <div>
                    <div class="stat-num"><?php echo $openTickets;?></div>
                    <div class="stat-lbl">Open Tickets</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Row: Orders + Side Panel -->
    <div class="row g-4 mb-4">

        <!-- Recent Orders -->
        <div class="col-lg-8">
            <div class="dash-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="dash-title">Recent Orders</div>
                    <a href="manage_orders.php" class="btn-view" style="font-size:0.78rem;">View All <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i></a>
                </div>

                <div style="overflow-x:auto;">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($recentOrders->num_rows > 0):
                                while($order = $recentOrders->fetch_assoc()):
                                    $sc = str_replace(' ','-',$order['status']);
                            ?>
                            <tr>
                                <td class="order-id-cell">#<?php echo $order['orderID'];?></td>
                                <td><?php echo htmlspecialchars($order['customer_name']);?></td>
                                <td class="order-amount">₱<?php echo number_format($order['total_amount'],2);?></td>
                                <td><span class="s-pill s-<?php echo $sc;?>"><?php echo $order['status'];?></span></td>
                                <td style="font-size:0.77rem;color:rgba(202,240,248,0.38);"><?php echo date('M j, g:i A', strtotime($order['order_date']));?></td>
                                <td style="text-align:right;">
                                    <a href="manage_orders.php?view=<?php echo $order['orderID'];?>" class="btn-view">View</a>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:32px;color:rgba(202,240,248,0.3);font-size:0.86rem;">No orders yet.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Side Panel -->
        <div class="col-lg-4">
            <!-- Quick Actions -->
            <div class="dash-card mb-4">
                <div class="dash-title mb-3">Quick Actions</div>
                <a href="manage_products.php?action=add" class="qa-btn">
                    <div class="qa-icon"><i class="fas fa-plus"></i></div>
                    <div><div class="qa-label">Add New Product</div><div class="qa-sub">Add to water catalogue</div></div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>
                <a href="manage_orders.php" class="qa-btn">
                    <div class="qa-icon gold"><i class="fas fa-tasks"></i></div>
                    <div><div class="qa-label">Process Orders</div><div class="qa-sub">Handle pending deliveries</div></div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>
                <a href="manage_users.php?filter=pending" class="qa-btn">
                    <div class="qa-icon green"><i class="fas fa-user-check"></i></div>
                    <div><div class="qa-label">Review Verifications</div><div class="qa-sub">Approve customer IDs</div></div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>
                <a href="support_tickets.php" class="qa-btn">
                    <div class="qa-icon violet"><i class="fas fa-headset"></i></div>
                    <div><div class="qa-label">Support Tickets</div><div class="qa-sub">Respond to customers</div></div>
                    <i class="fas fa-chevron-right qa-arrow"></i>
                </a>
            </div>

            <!-- System Overview -->
            <div class="dash-card">
                <div class="dash-title mb-1">System Overview</div>
                <div style="font-size:0.72rem;color:rgba(202,240,248,0.3);margin-bottom:16px;"><?php echo date('F Y');?></div>

                <div class="section-label">Activity</div>

                <div class="sys-row">
                    <span class="sys-label"><i class="fas fa-shopping-bag" style="color:var(--aqua);font-size:0.8rem;"></i> Orders Today</span>
                    <span class="sys-value"><?php echo $newOrdersToday;?></span>
                </div>
                <div class="sys-row">
                    <span class="sys-label"><i class="fas fa-peso-sign" style="color:var(--green);font-size:0.8rem;"></i> Today's Revenue</span>
                    <span class="sys-value" style="color:var(--green);">₱<?php echo number_format($todayRevenue,0);?></span>
                </div>
                <div class="sys-row">
                    <span class="sys-label"><i class="fas fa-user-clock" style="color:var(--gold);font-size:0.8rem;"></i> Pending Verif.</span>
                    <span class="sys-value" style="color:var(--gold);"><?php echo $pendingVerifications->num_rows;?></span>
                </div>
                <div class="sys-row">
                    <span class="sys-label"><i class="fas fa-box" style="color:var(--red);font-size:0.8rem;"></i> Low Stock Items</span>
                    <span class="sys-value" style="color:var(--red);"><?php echo count($lowStockProducts);?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Verifications -->
    <?php
    $pendingVerifications->data_seek(0);
    if($pendingVerifications->num_rows > 0):
    ?>
    <div class="dash-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="dash-title">Pending Account Verifications</div>
            <a href="manage_users.php?filter=pending" style="font-size:0.78rem;color:var(--gold);text-decoration:none;font-weight:600;">
                Review All <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i>
            </a>
        </div>

        <div style="overflow-x:auto;">
            <table class="verif-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Email</th>
                        <th>Registered</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($cust = $pendingVerifications->fetch_assoc()): ?>
                    <tr>
                        <td style="color:var(--white);font-weight:500;"><?php echo htmlspecialchars($cust['Firstname'].' '.$cust['Lastname']);?></td>
                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($cust['Email']);?></td>
                        <td style="font-size:0.78rem;color:rgba(202,240,248,0.38);"><?php echo date('M j, Y', strtotime($cust['created_at']));?></td>
                        <td style="text-align:right;">
                            <div style="display:flex;gap:6px;justify-content:flex-end;">
                                <a href="manage_users.php?verify=<?php echo $cust['userID'];?>" class="btn-approve">
                                    <i class="fas fa-check"></i> Approve
                                </a>
                                <a href="manage_users.php?reject=<?php echo $cust['userID'];?>" class="btn-reject">
                                    <i class="fas fa-xmark"></i> Reject
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

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
</script>
</body>
</html>