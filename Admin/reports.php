<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data for profile picture
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $_SESSION['userID'])->fetch_assoc();

// Date range filter (defaults to last 6 months)
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 months'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Fetch statistics (filtered by date)
$totalRevenue = $conn->query("SELECT SUM(total_amount) as total FROM orders WHERE status = 'Delivered' AND order_date BETWEEN '$dateFrom' AND '$dateTo'")->fetch_assoc()['total'] ?? 0;
$totalOrders = $conn->query("SELECT COUNT(*) as count FROM orders WHERE order_date BETWEEN '$dateFrom' AND '$dateTo'")->fetch_assoc()['count'] ?? 0;
$totalCustomers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE Role = 'customer'")->fetch_assoc()['count'] ?? 0;
$totalEmployees = 0;
try {
    $totalEmployees = $conn->query("SELECT COUNT(*) as count FROM customers WHERE Role = 'employee'")->fetch_assoc()['count'] ?? 0;
} catch (Exception $e) {}

// Average Order Value
$avgOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

// Monthly revenue
$monthlyRevenue = $conn->query("
    SELECT DATE_FORMAT(order_date, '%Y-%m') as month, 
           SUM(total_amount) as revenue,
           COUNT(*) as order_count
    FROM orders 
    WHERE status = 'Delivered' AND order_date BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY DATE_FORMAT(order_date, '%Y-%m')
    ORDER BY month ASC
");

// Top products
$topProducts = $conn->query("
    SELECT p.ProductName, 
           SUM(oi.quantity) as total_sold, 
           SUM(oi.quantity * oi.unit_price) as total_revenue
    FROM order_items oi
    JOIN product p ON oi.productID = p.ProductID
    JOIN orders o ON oi.orderID = o.orderID
    WHERE o.status = 'Delivered' AND o.order_date BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY p.ProductID, p.ProductName
    ORDER BY total_sold DESC
    LIMIT 6
");

// Order status
$statusResult = $conn->query("
    SELECT status, COUNT(*) as count, SUM(total_amount) as revenue
    FROM orders 
    WHERE order_date BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY status
    ORDER BY count DESC
");
$statusData = [];
while ($row = $statusResult->fetch_assoc()) {
    $statusData[] = $row;
}

// Prepare chart data
$monthlyLabels = [];
$monthlyRevenues = [];
$monthlyOrders = [];
while ($row = $monthlyRevenue->fetch_assoc()) {
    $monthlyLabels[] = date('M Y', strtotime($row['month'] . '-01'));
    $monthlyRevenues[] = (float)$row['revenue'];
    $monthlyOrders[] = (int)$row['order_count'];
}
$monthlyRevenue->data_seek(0);

$topProductNames = [];
$topProductSold = [];
$topProductRevenue = [];
while ($prod = $topProducts->fetch_assoc()) {
    $topProductNames[] = $prod['ProductName'];
    $topProductSold[] = (int)$prod['total_sold'];
    $topProductRevenue[] = (float)$prod['total_revenue'];
}
$topProducts->data_seek(0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Reports • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        .sidebar { 
            position: fixed; top: 0; left: 0; height: 100vh; width: 260px; 
            background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; 
            transition: all 0.3s ease; display: flex; flex-direction: column;
        }
        .sidebar .nav-menu { flex: 1; overflow-y: auto; padding-bottom: 20px; }
        .sidebar .logout-section { padding: 15px 10px; border-top: 1px solid #eee; background: white; }
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
        
        .stat-card { 
            background: white; border-radius: 20px; padding: 28px 24px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); transition: transform 0.2s;
            border-left: 5px solid var(--primary);
        }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-card .big-number { font-size: 2.4rem; font-weight: 700; line-height: 1; color: #1e293b; }
        .stat-card .label { font-size: 1.05rem; font-weight: 600; color: #334155; }
        .stat-card .hint { font-size: 0.875rem; color: #64748b; }
        
        .section-title { font-weight: 700; color: #1e293b; font-size: 1.35rem; }
        
        .friendly-box {
            background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            border-radius: 16px;
            padding: 20px 24px;
            border-left: 6px solid #0077B6;
        }
        
        .chart-container { position: relative; height: 300px; }
        
        .sortable-table th { cursor: pointer; user-select: none; transition: background 0.2s; }
        .sortable-table th:hover { background-color: #f1f5f9; }
        
        .print-header { display: none; }
        
        @media print {
            .sidebar, .btn, .dropdown, .no-print, .filter-bar { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 15px !important; }
            .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; page-break-inside: avoid; }
            .print-header { display: block !important; text-align: center; margin-bottom: 25px; }
            .print-header h1 { color: #023E8A; font-size: 2rem; }
            body { background: white; font-size: 14px; }
        }
        
        .metric-icon { width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 1.8rem; }
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
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="reports.php" class="nav-link active"><i class="fas fa-chart-bar me-3"></i> <span>Reports & Analytics</span></a></li>
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
        <!-- Top Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h3 class="fw-bold mb-1">Business Reports</h3>
                <p class="text-muted mb-0">Simple overview of your water delivery business • Updated just now</p>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <!-- Date Filter -->
                <div class="filter-bar d-flex align-items-center gap-2 bg-white px-3 py-2 rounded-pill shadow-sm">
                    <i class="fas fa-calendar text-primary"></i>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.7rem; line-height: 1;">From</small>
                            <input type="date" name="date_from" class="form-control form-control-sm border-0 p-0" value="<?php echo $dateFrom; ?>" style="width: 115px; font-size: 0.9rem;">
                        </div>
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.7rem; line-height: 1;">To</small>
                            <input type="date" name="date_to" class="form-control form-control-sm border-0 p-0" value="<?php echo $dateTo; ?>" style="width: 115px; font-size: 0.9rem;">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm px-3 rounded-pill">
                            <i class="fas fa-sync-alt me-1"></i> Update
                        </button>
                    </form>
                </div>
                
                <button onclick="printReport()" class="btn btn-success btn-lg px-4 py-2 rounded-pill shadow-sm no-print">
                    <i class="fas fa-print me-2"></i> <span class="fw-semibold">Print Report</span>
                </button>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                        <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                            <img src="../<?php echo $admin['profile_picture']; ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                <span class="fw-bold fs-5"><?php echo strtoupper(substr($adminName, 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="text-start d-none d-md-block">
                            <div class="fw-semibold"><?php echo htmlspecialchars($adminName); ?></div>
                            <small class="text-muted">Administrator</small>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Friendly Intro -->
        <div class="friendly-box mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-info-circle text-primary fa-2x me-3"></i>
                <div>
                    <strong class="fs-5">Welcome to your reports!</strong><br>
                    <span class="text-muted">This page shows how much money you're making, which products sell best, and how your team is doing. Change the dates above to see different time periods. Everything is designed to be easy to read.</span>
                </div>
            </div>
        </div>

        <!-- Print Header -->
        <div class="print-header">
            <img src="../images/logo.jpg" alt="Logo" style="width: 90px; height: 90px; border-radius: 50%; margin-bottom: 10px;">
            <h1 class="fw-bold">De Chavez Waterhaus</h1>
            <p class="fs-5 text-muted mb-1">Business Performance Report</p>
            <p class="text-muted"><?php echo date('F j, Y', strtotime($dateFrom)) . ' — ' . date('F j, Y', strtotime($dateTo)); ?></p>
            <hr class="my-3">
        </div>

        <!-- Big Number Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex align-items-center mb-3">
                        <div class="metric-icon bg-success text-white me-3">
                            <i class="fas fa-peso-sign"></i>
                        </div>
                        <div>
                            <div class="label">Money Earned</div>
                            <div class="hint">From delivered orders</div>
                        </div>
                    </div>
                    <div class="big-number">₱<?php echo number_format($totalRevenue, 0); ?></div>
                    <div class="mt-2 small text-success fw-semibold">
                        <i class="fas fa-check-circle me-1"></i> <?php echo number_format($totalOrders); ?> orders completed
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #00B4D8;">
                    <div class="d-flex align-items-center mb-3">
                        <div class="metric-icon bg-info text-white me-3">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div>
                            <div class="label">Orders Delivered</div>
                            <div class="hint">Total water containers</div>
                        </div>
                    </div>
                    <div class="big-number"><?php echo number_format($totalOrders); ?></div>
                    <div class="mt-2 small text-info fw-semibold">
                        Average order: ₱<?php echo number_format($avgOrderValue, 0); ?>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #10b981;">
                    <div class="d-flex align-items-center mb-3">
                        <div class="metric-icon bg-success text-white me-3">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="label">Happy Customers</div>
                            <div class="hint">Registered accounts</div>
                        </div>
                    </div>
                    <div class="big-number"><?php echo number_format($totalCustomers); ?></div>
                    <div class="mt-2 small text-success fw-semibold">
                        <i class="fas fa-heart me-1"></i> Your loyal base
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #f59e0b;">
                    <div class="d-flex align-items-center mb-3">
                        <div class="metric-icon bg-warning text-white me-3">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div>
                            <div class="label">Our Team</div>
                            <div class="hint">Employees working with you</div>
                        </div>
                    </div>
                    <div class="big-number"><?php echo number_format($totalEmployees); ?></div>
                    <div class="mt-2 small text-warning fw-semibold">
                        <i class="fas fa-hard-hat me-1"></i> Dedicated staff
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Revenue Trend -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3 px-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-chart-line text-primary fa-lg me-3"></i>
                            <div>
                                <h5 class="section-title mb-0">Money Coming In Each Month</h5>
                                <small class="text-muted">See how your sales are growing or slowing down</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <div class="chart-container">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Status -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3 px-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-tasks text-primary fa-lg me-3"></i>
                            <div>
                                <h5 class="section-title mb-0">Order Status Right Now</h5>
                                <small class="text-muted">Where your orders stand today</small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <div class="chart-container" style="height: 220px;">
                            <canvas id="statusPieChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php foreach ($statusData as $status): ?>
                                <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                    <div>
                                        <span class="fw-semibold"><?php echo htmlspecialchars($status['status']); ?></span>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold"><?php echo $status['count']; ?></span>
                                        <small class="text-muted d-block">₱<?php echo number_format($status['revenue'], 0); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <!-- Top Products -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="section-title mb-0"><i class="fas fa-trophy text-warning me-2"></i> Best Selling Water Types</h5>
                            <small class="text-muted">Which products your customers love most</small>
                        </div>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <div class="chart-container mb-3" style="height: 200px;">
                            <canvas id="topProductsChart"></canvas>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover sortable-table mb-0" id="topProductsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th onclick="sortTable('topProductsTable', 0, false)" style="cursor:pointer;">Product Name <i class="fas fa-sort ms-1 text-muted"></i></th>
                                        <th onclick="sortTable('topProductsTable', 1, true)" class="text-end" style="cursor:pointer;">Containers Sold <i class="fas fa-sort ms-1 text-muted"></i></th>
                                        <th onclick="sortTable('topProductsTable', 2, true)" class="text-end" style="cursor:pointer;">Total Sales <i class="fas fa-sort ms-1 text-muted"></i></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($topProductNames) > 0): ?>
                                        <?php for ($i = 0; $i < count($topProductNames); $i++): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo htmlspecialchars($topProductNames[$i]); ?></td>
                                                <td class="text-end"><span class="badge bg-primary"><?php echo number_format($topProductSold[$i]); ?></span></td>
                                                <td class="text-end fw-bold text-success">₱<?php echo number_format($topProductRevenue[$i], 0); ?></td>
                                            </tr>
                                        <?php endfor; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center py-4 text-muted">No sales data for this period yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i> Click any column header to sort (highest to lowest or A to Z)</small>
                    </div>
                </div>
            </div>

            <!-- Monthly Breakdown -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="section-title mb-0"><i class="fas fa-calendar text-info me-2"></i> Month-by-Month Summary</h5>
                            <small class="text-muted">Your performance every month</small>
                        </div>
                        <button onclick="exportTableToCSV('monthlyTable', 'monthly_sales.csv')" class="btn btn-sm btn-outline-secondary no-print">
                            <i class="fas fa-download me-1"></i> Download
                        </button>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <div class="table-responsive">
                            <table class="table table-hover sortable-table mb-0" id="monthlyTable">
                                <thead class="table-light">
                                    <tr>
                                        <th onclick="sortTable('monthlyTable', 0, false)" style="cursor:pointer;">Month <i class="fas fa-sort ms-1 text-muted"></i></th>
                                        <th onclick="sortTable('monthlyTable', 1, true)" class="text-end" style="cursor:pointer;">Orders <i class="fas fa-sort ms-1 text-muted"></i></th>
                                        <th onclick="sortTable('monthlyTable', 2, true)" class="text-end" style="cursor:pointer;">Revenue <i class="fas fa-sort ms-1 text-muted"></i></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($monthlyRevenue->num_rows > 0): ?>
                                        <?php while ($row = $monthlyRevenue->fetch_assoc()) { ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo date('F Y', strtotime($row['month'] . '-01')); ?></td>
                                                <td class="text-end"><?php echo number_format($row['order_count']); ?></td>
                                                <td class="text-end fw-bold">₱<?php echo number_format($row['revenue'], 0); ?></td>
                                            </tr>
                                        <?php } ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center py-4 text-muted">No data for this date range.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td>Total for Period</td>
                                        <td class="text-end"><?php echo number_format($totalOrders); ?></td>
                                        <td class="text-end">₱<?php echo number_format($totalRevenue, 0); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i> Click column headers to sort. Use the Download button to save as spreadsheet.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Tips -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm bg-light">
                    <div class="card-body py-3 px-4">
                        <div class="d-flex flex-wrap align-items-center gap-4">
                            <div class="fw-semibold text-primary"><i class="fas fa-lightbulb me-2"></i> Quick Tips for Non-Tech Users:</div>
                            <div class="small text-muted">
                                • Change dates at the top and click <strong>Update</strong> to refresh everything<br>
                                • Click any blue column header to sort the tables<br>
                                • Use the big green <strong>Print Report</strong> button to make a paper copy for meetings<br>
                                • The charts update automatically when you change dates
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile sidebar
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
            document.addEventListener('click', e => {
                if (window.innerWidth < 992 && !sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        }

        // Revenue Trend Chart
        const revenueCtx = document.getElementById('revenueTrendChart');
        if (revenueCtx) {
            new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthlyLabels); ?>,
                    datasets: [{
                        label: 'Revenue (₱)',
                        data: <?php echo json_encode($monthlyRevenues); ?>,
                        borderColor: '#0077B6',
                        backgroundColor: 'rgba(0, 119, 182, 0.15)',
                        borderWidth: 4,
                        tension: 0.35,
                        fill: true,
                        pointBackgroundColor: '#023E8A',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 3,
                        pointRadius: 6
                    }, {
                        label: 'Orders',
                        data: <?php echo json_encode($monthlyOrders); ?>,
                        borderColor: '#00B4D8',
                        backgroundColor: 'rgba(0, 180, 216, 0.1)',
                        borderWidth: 2,
                        tension: 0.35,
                        yAxisID: 'y1',
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, padding: 20, font: { size: 13 } } },
                        tooltip: { mode: 'index', intersect: false, backgroundColor: '#023E8A' }
                    },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Revenue in Pesos (₱)', font: { size: 12 } }, grid: { color: '#e2e8f0' } },
                        y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Number of Orders', font: { size: 12 } }, grid: { drawOnChartArea: false } },
                        x: { grid: { color: '#e2e8f0' } }
                    }
                }
            });
        }

        // Top Products Bar Chart
        const topCtx = document.getElementById('topProductsChart');
        if (topCtx) {
            new Chart(topCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($topProductNames); ?>,
                    datasets: [{
                        label: 'Containers Sold',
                        data: <?php echo json_encode($topProductSold); ?>,
                        backgroundColor: '#0077B6',
                        borderColor: '#023E8A',
                        borderWidth: 1,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, grid: { color: '#e2e8f0' } },
                        y: { grid: { display: false } }
                    }
                }
            });
        }

        // Order Status Doughnut
        const statusCtx = document.getElementById('statusPieChart');
        if (statusCtx) {
            const labels = <?php echo json_encode(array_column($statusData, 'status')); ?>;
            const counts = <?php echo json_encode(array_column($statusData, 'count')); ?>;
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: counts,
                        backgroundColor: ['#0077B6', '#00B4D8', '#48CAE4', '#90E0EF', '#CAF0F8'],
                        borderWidth: 3,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { padding: 15, usePointStyle: true, font: { size: 12 } } }
                    },
                    cutout: '68%'
                }
            });
        }

        // Table Sorting
        function sortTable(tableId, colIndex, isNumeric) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const isAsc = table.dataset.sortDir !== 'asc';
            table.dataset.sortDir = isAsc ? 'asc' : 'desc';

            rows.sort((a, b) => {
                let aVal = a.children[colIndex].textContent.trim();
                let bVal = b.children[colIndex].textContent.trim();
                if (isNumeric) {
                    aVal = parseFloat(aVal.replace(/[^0-9.-]/g, '')) || 0;
                    bVal = parseFloat(bVal.replace(/[^0-9.-]/g, '')) || 0;
                } else {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }
                return isAsc ? (aVal < bVal ? -1 : aVal > bVal ? 1 : 0) : (aVal > bVal ? -1 : aVal < bVal ? 1 : 0);
            });
            rows.forEach(r => tbody.appendChild(r));
        }

        // Export to CSV
        function exportTableToCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;
            let csv = [];
            table.querySelectorAll('tr').forEach(row => {
                const cols = row.querySelectorAll('td, th');
                csv.push(Array.from(cols).map(c => `"${c.textContent.trim().replace(/"/g, '""')}"`).join(','));
            });
            const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename; a.click();
            URL.revokeObjectURL(url);
        }

        // Print
        function printReport() {
            window.print();
        }

        // Keyboard print
        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
                e.preventDefault();
                printReport();
            }
        });

        console.log('%c[Reports] Super friendly version loaded for non-technical users!', 'color:#10b981');
    </script>
</body>
</html>