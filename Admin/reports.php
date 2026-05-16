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

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months'));
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

$totalRevenue   = $conn->query("SELECT SUM(total_amount) as t FROM orders WHERE status='Delivered' AND order_date BETWEEN '$dateFrom' AND '$dateTo'")->fetch_assoc()['t'] ?? 0;
$totalOrders    = $conn->query("SELECT COUNT(*) as c FROM orders WHERE order_date BETWEEN '$dateFrom' AND '$dateTo'")->fetch_assoc()['c'] ?? 0;
$totalCustomers = $conn->query("SELECT COUNT(*) as c FROM customers WHERE Role='customer'")->fetch_assoc()['c'] ?? 0;
$totalEmployees = 0;
try { $totalEmployees = $conn->query("SELECT COUNT(*) as c FROM customers WHERE Role='employee'")->fetch_assoc()['c'] ?? 0; } catch(Exception $e) {}
$avgOrderValue  = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

$monthlyRevenue = $conn->query("
    SELECT DATE_FORMAT(order_date,'%Y-%m') as month, SUM(total_amount) as revenue, COUNT(*) as order_count
    FROM orders WHERE status='Delivered' AND order_date BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY DATE_FORMAT(order_date,'%Y-%m') ORDER BY month ASC
");

$topProducts = $conn->query("
    SELECT p.ProductName, SUM(oi.quantity) as total_sold, SUM(oi.quantity*oi.unit_price) as total_revenue
    FROM order_items oi JOIN product p ON oi.productID=p.ProductID JOIN orders o ON oi.orderID=o.orderID
    WHERE o.status='Delivered' AND o.order_date BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY p.ProductID, p.ProductName ORDER BY total_sold DESC LIMIT 6
");

$statusResult = $conn->query("SELECT status, COUNT(*) as count, SUM(total_amount) as revenue FROM orders WHERE order_date BETWEEN '$dateFrom' AND '$dateTo' GROUP BY status ORDER BY count DESC");
$statusData = [];
while($row = $statusResult->fetch_assoc()) $statusData[] = $row;

$monthlyLabels = []; $monthlyRevenues = []; $monthlyOrders2 = [];
while($row = $monthlyRevenue->fetch_assoc()) {
    $monthlyLabels[]    = date('M Y', strtotime($row['month'].'-01'));
    $monthlyRevenues[]  = (float)$row['revenue'];
    $monthlyOrders2[]   = (int)$row['order_count'];
}
$monthlyRevenue->data_seek(0);

$topProductNames = []; $topProductSold = []; $topProductRevenue = [];
while($prod = $topProducts->fetch_assoc()) {
    $topProductNames[]   = $prod['ProductName'];
    $topProductSold[]    = (int)$prod['total_sold'];
    $topProductRevenue[] = (float)$prod['total_revenue'];
}
$topProducts->data_seek(0);

$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports & Analytics • Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="icon" href="../images/logo.jpg" type="image/x-icon">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
    --deep:#020d18; --abyss:#030f1e; --ocean:#041e35; --navy:#0a2d4a;
    --teal:#0077b6; --aqua:#00b4d8; --cyan:#48cae4;
    --foam:#caf0f8; --white:#f0f9ff; --gold:#f4c842;
    --green:#4ade80; --red:#f87171; --violet:#a78bfa;
    --glass:rgba(0,180,216,0.08); --glass-border:rgba(72,202,228,0.18);
    --sidebar-w:260px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--deep);color:var(--white);min-height:100vh;}

/* SIDEBAR */
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-w);background:var(--abyss);border-right:1px solid var(--glass-border);z-index:1000;display:flex;flex-direction:column;transition:transform .3s ease;}
.sidebar-logo{padding:22px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--glass-border);flex-shrink:0;}
.sidebar-logo img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:1px solid rgba(0,180,216,.35);}
.sidebar-logo-text{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:500;color:var(--white);line-height:1.2;}
.sidebar-logo-sub{font-size:.65rem;color:rgba(202,240,248,.3);letter-spacing:.1em;text-transform:uppercase;}
.sidebar-nav{flex:1;overflow-y:auto;padding:12px 10px;scrollbar-width:thin;scrollbar-color:rgba(72,202,228,.15) transparent;}
.sidebar-nav::-webkit-scrollbar{width:3px;}
.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(72,202,228,.15);border-radius:2px;}
.nav-section-label{font-size:.58rem;letter-spacing:.2em;text-transform:uppercase;color:rgba(202,240,248,.22);padding:14px 10px 5px;}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;color:rgba(202,240,248,.48)!important;text-decoration:none;font-size:.84rem;font-weight:500;transition:all .22s ease;margin-bottom:1px;position:relative;}
.nav-link i{width:16px;text-align:center;font-size:.85rem;color:rgba(0,180,216,.38);transition:color .22s;}
.nav-link:hover{background:var(--glass);color:var(--foam)!important;}
.nav-link:hover i{color:var(--aqua);}
.nav-link.active{background:linear-gradient(135deg,rgba(0,119,182,.25),rgba(0,180,216,.12));border:1px solid rgba(0,180,216,.2);color:var(--aqua)!important;}
.nav-link.active i{color:var(--aqua);}
.nav-link.active::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:3px;background:var(--aqua);border-radius:0 3px 3px 0;}
.nav-link.danger{color:rgba(252,165,165,.6)!important;}
.nav-link.danger i{color:rgba(252,165,165,.5);}
.nav-link.danger:hover{background:rgba(248,113,113,.08);color:#fca5a5!important;}
.sidebar-footer{padding:12px 10px;border-top:1px solid var(--glass-border);flex-shrink:0;}

/* MAIN */
.main-content{margin-left:var(--sidebar-w);min-height:100vh;padding:26px 30px;}

/* TOPBAR */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:14px;}
.topbar-left h4{font-family:'Cormorant Garamond',serif;font-size:1.65rem;font-weight:400;color:var(--white);line-height:1.1;}
.topbar-left p{font-size:.8rem;color:rgba(202,240,248,.4);margin-top:2px;}
.topbar-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.topbar-btn{width:40px;height:40px;border-radius:50%;background:var(--glass);border:1px solid var(--glass-border);color:rgba(202,240,248,.6);display:flex;align-items:center;justify-content:center;font-size:.88rem;text-decoration:none;transition:all .3s;position:relative;cursor:pointer;}
.topbar-btn:hover{background:rgba(0,180,216,.15);border-color:var(--aqua);color:var(--aqua);}
.topbar-notif-badge{position:absolute;top:-3px;right:-3px;background:var(--gold);color:var(--deep);font-size:.55rem;font-weight:700;min-width:15px;height:15px;border-radius:50px;display:flex;align-items:center;justify-content:center;padding:0 3px;}
.avatar-btn{display:flex;align-items:center;gap:9px;background:var(--glass);border:1px solid var(--glass-border);border-radius:50px;padding:5px 12px 5px 5px;cursor:pointer;transition:all .3s;}
.avatar-btn:hover{border-color:rgba(0,180,216,.35);background:rgba(0,180,216,.1);}
.avatar-circle{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);font-weight:700;font-size:.82rem;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
.avatar-circle img{width:100%;height:100%;object-fit:cover;}
.avatar-name{font-size:.8rem;font-weight:500;color:var(--white);}
.avatar-role{font-size:.68rem;color:rgba(202,240,248,.4);}
.dropdown-menu{background:var(--ocean)!important;border:1px solid var(--glass-border)!important;border-radius:13px!important;padding:7px!important;box-shadow:0 18px 48px rgba(0,0,0,.5)!important;}
.dropdown-item{color:rgba(202,240,248,.65)!important;border-radius:7px!important;padding:8px 13px!important;font-size:.83rem!important;transition:all .2s!important;}
.dropdown-item:hover{background:var(--glass)!important;color:var(--aqua)!important;}
.dropdown-item.text-danger{color:rgba(252,165,165,.7)!important;}
.dropdown-divider{border-color:var(--glass-border)!important;margin:4px 0!important;}

/* DATE FILTER */
.date-filter{display:flex;align-items:center;gap:8px;background:rgba(4,30,53,.7);border:1px solid var(--glass-border);border-radius:50px;padding:8px 16px;flex-wrap:wrap;}
.date-filter label{font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:rgba(202,240,248,.35);white-space:nowrap;}
.date-input{background:transparent;border:none;color:var(--white);font-family:'DM Sans',sans-serif;font-size:.84rem;outline:none;width:120px;}
.date-input::-webkit-calendar-picker-indicator{filter:invert(.7);cursor:pointer;}
.date-sep{color:rgba(202,240,248,.25);}
.btn-update{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,var(--teal),var(--aqua));border:none;border-radius:50px;color:var(--deep);font-family:'DM Sans',sans-serif;font-size:.76rem;font-weight:700;padding:7px 16px;cursor:pointer;transition:all .3s;}
.btn-update:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,180,216,.3);}
.btn-print{display:inline-flex;align-items:center;gap:6px;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:var(--green);border-radius:50px;font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:700;padding:9px 18px;cursor:pointer;transition:all .3s;}
.btn-print:hover{background:rgba(74,222,128,.2);transform:translateY(-1px);}

/* STAT CARDS */
.stat-card{background:linear-gradient(145deg,rgba(10,45,74,.65),rgba(3,15,30,.85));border:1px solid var(--glass-border);border-radius:16px;padding:22px;display:flex;align-items:center;gap:16px;transition:all .3s;}
.stat-card:hover{transform:translateY(-4px);border-color:rgba(0,180,216,.25);box-shadow:0 16px 40px rgba(0,0,0,.3);}
.stat-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
.si-green{background:rgba(74,222,128,.12);color:var(--green);}
.si-blue{background:rgba(0,180,216,.12);color:var(--aqua);}
.si-teal{background:rgba(0,119,182,.15);color:#7dd3fc;}
.si-gold{background:rgba(244,200,66,.1);color:var(--gold);}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:600;color:var(--white);line-height:1;}
.stat-lbl{font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:rgba(202,240,248,.35);margin-top:3px;}
.stat-sub{font-size:.76rem;color:rgba(202,240,248,.42);margin-top:5px;}

/* DATA CARDS */
.data-card{background:linear-gradient(145deg,rgba(10,45,74,.5),rgba(3,15,30,.75));border:1px solid var(--glass-border);border-radius:17px;overflow:hidden;height:100%;}
.data-card-head{display:flex;justify-content:space-between;align-items:flex-start;padding:18px 22px;border-bottom:1px solid var(--glass-border);}
.data-card-title{font-family:'Cormorant Garamond',serif;font-size:1.12rem;font-weight:500;color:var(--white);}
.data-card-sub{font-size:.74rem;color:rgba(202,240,248,.35);margin-top:3px;}
.data-card-body{padding:22px;}

/* STATUS TABLE */
.status-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(72,202,228,.06);}
.status-row:last-child{border-bottom:none;}
.s-label{font-size:.86rem;color:var(--foam);font-weight:500;}
.s-count{font-family:'Cormorant Garamond',serif;font-size:1.05rem;font-weight:600;color:var(--white);}
.s-rev{font-size:.75rem;color:rgba(202,240,248,.38);}

/* REPORT TABLE */
.rpt-table{width:100%;border-collapse:collapse;}
.rpt-table th{font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;color:rgba(202,240,248,.3);padding:0 14px 10px;text-align:left;border-bottom:1px solid var(--glass-border);cursor:pointer;user-select:none;}
.rpt-table th:hover{color:var(--aqua);}
.rpt-table td{padding:12px 14px;font-size:.84rem;color:rgba(202,240,248,.7);border-bottom:1px solid rgba(72,202,228,.06);vertical-align:middle;}
.rpt-table tbody tr:last-child td{border-bottom:none;}
.rpt-table tbody tr:hover td{background:rgba(0,180,216,.03);color:var(--foam);}
.rpt-table tfoot td{font-weight:700;color:var(--white);border-top:1px solid var(--glass-border);padding-top:13px;font-family:'Cormorant Garamond',serif;font-size:.95rem;}
.prod-sold-badge{display:inline-flex;align-items:center;background:rgba(0,180,216,.1);border:1px solid rgba(0,180,216,.22);color:var(--aqua);padding:3px 10px;border-radius:50px;font-size:.74rem;font-weight:700;}
.rev-val{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:600;color:var(--green);}
.btn-csv{display:inline-flex;align-items:center;gap:5px;background:var(--glass);border:1px solid var(--glass-border);color:rgba(202,240,248,.55);border-radius:50px;font-size:.74rem;font-weight:600;padding:5px 13px;cursor:pointer;transition:all .25s;}
.btn-csv:hover{background:rgba(0,180,216,.12);color:var(--aqua);border-color:rgba(0,180,216,.28);}
.tips-box{background:linear-gradient(135deg,rgba(0,119,182,.12),rgba(0,180,216,.06));border:1px solid rgba(0,180,216,.18);border-left:4px solid var(--aqua);border-radius:14px;padding:16px 20px;margin-top:22px;font-size:.82rem;color:rgba(202,240,248,.5);}
.tips-box strong{color:var(--aqua);}

/* PRINT */
.print-header{display:none;}
@media print {
    .sidebar,.sidebar-overlay,.topbar,.no-print{display:none!important;}
    .main-content{margin-left:0!important;padding:0!important;background:white!important;color:#1a1a1a!important;}
    body{background:white!important;}
    .print-header{display:block!important;text-align:center;margin-bottom:24px;}
    .data-card,.stat-card{background:white!important;border:1px solid #cbd5e1!important;box-shadow:none!important;page-break-inside:avoid;}
    .rpt-table th,.rpt-table td{color:#1a1a1a!important;}
}

/* MOBILE */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(2,13,24,.7);z-index:999;backdrop-filter:blur(3px);}
.mobile-toggle{background:var(--glass);border:1px solid var(--glass-border);color:var(--aqua);width:38px;height:38px;border-radius:9px;display:none;align-items:center;justify-content:center;cursor:pointer;font-size:.88rem;}
@media(max-width:991px){
    .sidebar{transform:translateX(-100%);box-shadow:4px 0 40px rgba(0,0,0,.5);}
    .sidebar.show{transform:translateX(0);}
    .sidebar-overlay.show{display:block;}
    .main-content{margin-left:0;padding:18px 16px;}
    .mobile-toggle{display:flex;}
}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="">
        <div><div class="sidebar-logo-text">De Chavez Waterhaus</div><div class="sidebar-logo-sub">Admin Panel</div></div>
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
        <a href="payroll_management.php"    class="nav-link"><i class="fas fa-money-bill"></i> Payroll</a>
        <a href="generate_payslip.php"      class="nav-link"><i class="fas fa-file-pdf"></i> Generate Payslip</a>
        <a href="leave_management.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Manage Leave</a>
        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php"   class="nav-link"><i class="fas fa-headset"></i> Support Tickets</a>
        <a href="reports.php"           class="nav-link active"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="nav-section-label" style="margin-top:14px;"></div>
        <a href="profile.php"           class="nav-link"><i class="fas fa-user"></i> My Profile</a>
        <a href="../logout.php"         class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<main class="main-content">

    <div class="print-header">
        <img src="../images/logo.jpg" alt="" style="width:70px;height:70px;border-radius:50%;margin-bottom:10px;">
        <h2 style="font-family:Georgia,serif;color:#023E8A;">De Chavez Waterhaus</h2>
        <p style="color:#666;">Business Performance Report · <?php echo date('F j, Y',strtotime($dateFrom)).' — '.date('F j, Y',strtotime($dateTo));?></p><hr>
    </div>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <h4>Reports &amp; Analytics</h4>
                <p><?php echo date('M j, Y',strtotime($dateFrom));?> – <?php echo date('M j, Y',strtotime($dateTo));?></p>
            </div>
        </div>
        <div class="topbar-right">
            <form method="GET" class="no-print">
                <div class="date-filter">
                    <label>From</label>
                    <input type="date" name="date_from" class="date-input" value="<?php echo $dateFrom;?>">
                    <span class="date-sep">–</span>
                    <label>To</label>
                    <input type="date" name="date_to" class="date-input" value="<?php echo $dateTo;?>">
                    <button type="submit" class="btn-update"><i class="fas fa-sync-alt"></i> Update</button>
                </div>
            </form>
            <button class="btn-print no-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="notifications.php" class="topbar-btn no-print">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
            </a>
            <div class="dropdown no-print">
                <div class="avatar-btn" data-bs-toggle="dropdown">
                    <div class="avatar-circle">
                        <?php if(!empty($admin['profile_picture'])&&file_exists('../'.$admin['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($admin['profile_picture']);?>" alt="">
                        <?php else: echo strtoupper(substr($adminName,0,1)); endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($adminName);?></div>
                        <div class="avatar-role">Administrator</div>
                    </div>
                    <i class="fas fa-chevron-down fa-xs ms-1" style="color:rgba(202,240,248,.3);"></i>
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
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-peso-sign"></i></div>
                <div><div class="stat-num">₱<?php echo number_format($totalRevenue,0);?></div><div class="stat-lbl">Revenue</div><div class="stat-sub">Avg ₱<?php echo number_format($avgOrderValue,0);?>/order</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-shopping-cart"></i></div>
                <div><div class="stat-num"><?php echo number_format($totalOrders);?></div><div class="stat-lbl">Total Orders</div><div class="stat-sub">In selected period</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon si-teal"><i class="fas fa-users"></i></div>
                <div><div class="stat-num"><?php echo number_format($totalCustomers);?></div><div class="stat-lbl">Customers</div><div class="stat-sub">Registered accounts</div></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon si-gold"><i class="fas fa-user-tie"></i></div>
                <div><div class="stat-num"><?php echo number_format($totalEmployees);?></div><div class="stat-lbl">Employees</div><div class="stat-sub">Active staff</div></div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="data-card">
                <div class="data-card-head">
                    <div><div class="data-card-title"><i class="fas fa-chart-line me-2" style="color:var(--aqua);font-size:.9rem;"></i>Monthly Revenue Trend</div><div class="data-card-sub">Revenue &amp; order count per month</div></div>
                </div>
                <div class="data-card-body"><div style="position:relative;height:280px;"><canvas id="revenueChart"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="data-card">
                <div class="data-card-head">
                    <div><div class="data-card-title"><i class="fas fa-circle-half-stroke me-2" style="color:var(--aqua);font-size:.9rem;"></i>Order Status Breakdown</div><div class="data-card-sub">Distribution of all orders</div></div>
                </div>
                <div class="data-card-body">
                    <div style="position:relative;height:180px;margin-bottom:16px;"><canvas id="statusChart"></canvas></div>
                    <?php foreach($statusData as $s): ?>
                    <div class="status-row">
                        <span class="s-label"><?php echo htmlspecialchars($s['status']);?></span>
                        <div style="text-align:right;"><div class="s-count"><?php echo number_format($s['count']);?></div><div class="s-rev">₱<?php echo number_format($s['revenue'],0);?></div></div>
                    </div>
                    <?php endforeach; if(empty($statusData)): ?>
                    <div style="text-align:center;color:rgba(202,240,248,.3);padding:20px;font-size:.85rem;">No data for this period.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tables Row -->
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="data-card">
                <div class="data-card-head">
                    <div><div class="data-card-title"><i class="fas fa-trophy me-2" style="color:var(--gold);font-size:.9rem;"></i>Best Selling Products</div><div class="data-card-sub">Click headers to sort</div></div>
                </div>
                <div class="data-card-body">
                    <div style="position:relative;height:180px;margin-bottom:20px;"><canvas id="productsChart"></canvas></div>
                    <table class="rpt-table" id="productsTable">
                        <thead><tr>
                            <th onclick="sortTable('productsTable',0,false)">Product <i class="fas fa-sort" style="font-size:.6rem;"></i></th>
                            <th onclick="sortTable('productsTable',1,true)" style="text-align:right;">Sold <i class="fas fa-sort" style="font-size:.6rem;"></i></th>
                            <th onclick="sortTable('productsTable',2,true)" style="text-align:right;">Revenue <i class="fas fa-sort" style="font-size:.6rem;"></i></th>
                        </tr></thead>
                        <tbody>
                            <?php if(count($topProductNames)>0): for($i=0;$i<count($topProductNames);$i++): ?>
                            <tr>
                                <td style="font-weight:500;color:var(--white);"><?php echo htmlspecialchars($topProductNames[$i]);?></td>
                                <td style="text-align:right;"><span class="prod-sold-badge"><?php echo number_format($topProductSold[$i]);?></span></td>
                                <td style="text-align:right;"><span class="rev-val">₱<?php echo number_format($topProductRevenue[$i],0);?></span></td>
                            </tr>
                            <?php endfor; else: ?>
                            <tr><td colspan="3" style="text-align:center;color:rgba(202,240,248,.3);padding:24px;">No sales data for this period.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="data-card">
                <div class="data-card-head">
                    <div><div class="data-card-title"><i class="fas fa-calendar me-2" style="color:var(--violet);font-size:.9rem;"></i>Month-by-Month Summary</div><div class="data-card-sub">Click headers to sort</div></div>
                    <button class="btn-csv no-print" onclick="exportCSV('monthlyTable','monthly_report.csv')"><i class="fas fa-download"></i> CSV</button>
                </div>
                <div class="data-card-body">
                    <table class="rpt-table" id="monthlyTable">
                        <thead><tr>
                            <th onclick="sortTable('monthlyTable',0,false)">Month <i class="fas fa-sort" style="font-size:.6rem;"></i></th>
                            <th onclick="sortTable('monthlyTable',1,true)" style="text-align:right;">Orders <i class="fas fa-sort" style="font-size:.6rem;"></i></th>
                            <th onclick="sortTable('monthlyTable',2,true)" style="text-align:right;">Revenue <i class="fas fa-sort" style="font-size:.6rem;"></i></th>
                        </tr></thead>
                        <tbody>
                            <?php if($monthlyRevenue->num_rows>0): while($row=$monthlyRevenue->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight:500;color:var(--white);"><?php echo date('F Y',strtotime($row['month'].'-01'));?></td>
                                <td style="text-align:right;color:var(--aqua);font-family:'Cormorant Garamond',serif;font-size:1rem;"><?php echo number_format($row['order_count']);?></td>
                                <td style="text-align:right;"><span class="rev-val">₱<?php echo number_format($row['revenue'],0);?></span></td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="3" style="text-align:center;color:rgba(202,240,248,.3);padding:24px;">No data for this date range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot><tr>
                            <td>Total for Period</td>
                            <td style="text-align:right;"><?php echo number_format($totalOrders);?></td>
                            <td style="text-align:right;color:var(--green);">₱<?php echo number_format($totalRevenue,0);?></td>
                        </tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="tips-box no-print">
        <strong><i class="fas fa-lightbulb me-2"></i>Tips:</strong>
        Change the date range and click <strong>Update</strong> to refresh all charts. Click column headers to sort tables. Use <strong>Print</strong> for a printable report or <strong>CSV</strong> to download the monthly summary.
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay'),toggle=document.getElementById('mobileToggle');
function openSidebar(){sidebar.classList.add('show');overlay.classList.add('show');}
function closeSidebar(){sidebar.classList.remove('show');overlay.classList.remove('show');}
if(toggle)toggle.addEventListener('click',openSidebar);
if(overlay)overlay.addEventListener('click',closeSidebar);
sidebar.querySelectorAll('.nav-link').forEach(l=>l.addEventListener('click',()=>{if(window.innerWidth<992)closeSidebar();}));

Chart.defaults.color='rgba(202,240,248,.45)';
Chart.defaults.borderColor='rgba(72,202,228,.1)';

const rCtx=document.getElementById('revenueChart');
if(rCtx){new Chart(rCtx,{type:'line',data:{labels:<?php echo json_encode($monthlyLabels);?>,datasets:[{label:'Revenue (₱)',data:<?php echo json_encode($monthlyRevenues);?>,borderColor:'#00b4d8',backgroundColor:'rgba(0,180,216,.12)',borderWidth:3,tension:.4,fill:true,pointBackgroundColor:'#48cae4',pointBorderColor:'#020d18',pointBorderWidth:2,pointRadius:5},{label:'Orders',data:<?php echo json_encode($monthlyOrders2);?>,borderColor:'#a78bfa',backgroundColor:'rgba(167,139,250,.08)',borderWidth:2,tension:.4,yAxisID:'y1',pointRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:16,font:{size:12}}},tooltip:{mode:'index',intersect:false,backgroundColor:'rgba(4,30,53,.95)',borderColor:'rgba(72,202,228,.25)',borderWidth:1}},scales:{y:{beginAtZero:true,grid:{color:'rgba(72,202,228,.08)'},ticks:{color:'rgba(202,240,248,.4)'}},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{color:'rgba(167,139,250,.5)'}},x:{grid:{color:'rgba(72,202,228,.06)'},ticks:{color:'rgba(202,240,248,.4)'}}}}});}

const pCtx=document.getElementById('productsChart');
if(pCtx){new Chart(pCtx,{type:'bar',data:{labels:<?php echo json_encode($topProductNames);?>,datasets:[{label:'Sold',data:<?php echo json_encode($topProductSold);?>,backgroundColor:'rgba(0,180,216,.5)',borderColor:'#00b4d8',borderWidth:1,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false},tooltip:{backgroundColor:'rgba(4,30,53,.95)',borderColor:'rgba(72,202,228,.25)',borderWidth:1}},scales:{x:{beginAtZero:true,grid:{color:'rgba(72,202,228,.08)'},ticks:{color:'rgba(202,240,248,.4)'}},y:{grid:{display:false},ticks:{color:'rgba(202,240,248,.55)'}}}}});}

const sCtx=document.getElementById('statusChart');
if(sCtx){new Chart(sCtx,{type:'doughnut',data:{labels:<?php echo json_encode(array_column($statusData,'status'));?>,datasets:[{data:<?php echo json_encode(array_map('intval',array_column($statusData,'count')));?>,backgroundColor:['#0077b6','#00b4d8','#48cae4','#90e0ef','#4ade80','#f87171'],borderWidth:2,borderColor:'#030f1e'}]},options:{responsive:true,maintainAspectRatio:false,cutout:'70%',plugins:{legend:{position:'right',labels:{padding:12,usePointStyle:true,font:{size:11},color:'rgba(202,240,248,.55)'}},tooltip:{backgroundColor:'rgba(4,30,53,.95)',borderColor:'rgba(72,202,228,.25)',borderWidth:1}}}});}

function sortTable(id,col,isNum){
    const tbl=document.getElementById(id);if(!tbl)return;
    const tbody=tbl.querySelector('tbody');const rows=Array.from(tbody.querySelectorAll('tr'));
    const asc=tbl.dataset.dir!=='asc';tbl.dataset.dir=asc?'asc':'desc';
    rows.sort((a,b)=>{
        let av=a.children[col]?.textContent.trim()??'';let bv=b.children[col]?.textContent.trim()??'';
        if(isNum){av=parseFloat(av.replace(/[^0-9.-]/g,''))||0;bv=parseFloat(bv.replace(/[^0-9.-]/g,''))||0;}
        else{av=av.toLowerCase();bv=bv.toLowerCase();}
        return asc?(av<bv?-1:av>bv?1:0):(av>bv?-1:av<bv?1:0);
    });
    rows.forEach(r=>tbody.appendChild(r));
}

function exportCSV(id,fn){
    const tbl=document.getElementById(id);if(!tbl)return;
    const csv=Array.from(tbl.querySelectorAll('tr')).map(r=>Array.from(r.querySelectorAll('td,th')).map(c=>`"${c.textContent.trim().replace(/"/g,'""')}"`).join(',')).join('\n');
    const a=Object.assign(document.createElement('a'),{href:URL.createObjectURL(new Blob([csv],{type:'text/csv'})),download:fn});a.click();
}
</script>
</body>
</html>