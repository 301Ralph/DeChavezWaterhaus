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
// Create uploads directory if not exists
$uploadDir = '../uploads/products/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
// Handle Add Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $productName = htmlspecialchars($_POST['product_name']);
    $description = htmlspecialchars($_POST['description']);
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);
    $status = $_POST['status'];
    $imageURL = '';
    // Handle image upload
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $fileType = $_FILES['product_image']['type'];
       
        if (in_array($fileType, $allowedTypes)) {
            $fileName = time() . '_' . basename($_FILES['product_image']['name']);
            $targetPath = $uploadDir . $fileName;
           
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $targetPath)) {
                $imageURL = 'uploads/products/' . $fileName;
            }
        }
    }
    $stmt = $conn->prepare("INSERT INTO product (ProductName, Description, Price, Stock, Status, ImageURL) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdiss", $productName, $description, $price, $stock, $status, $imageURL);
   
    if ($stmt->execute()) {
        echo '<script>alert("Product added successfully!"); window.location = "manage_products.php";</script>';
    } else {
        echo '<script>alert("Error adding product.");</script>';
    }
    $stmt->close();
}
// Handle Update Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    $productID = intval($_POST['productID']);
    $productName = htmlspecialchars($_POST['product_name']);
    $description = htmlspecialchars($_POST['description']);
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);
    $status = $_POST['status'];
    $currentImage = $_POST['current_image'] ?? '';
    $imageURL = $currentImage;
    // Handle new image upload
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $fileType = $_FILES['product_image']['type'];
       
        if (in_array($fileType, $allowedTypes)) {
            $fileName = time() . '_' . basename($_FILES['product_image']['name']);
            $targetPath = $uploadDir . $fileName;
           
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $targetPath)) {
                // Delete old image if exists
                if (!empty($currentImage) && file_exists('../' . $currentImage)) {
                    unlink('../' . $currentImage);
                }
                $imageURL = 'uploads/products/' . $fileName;
            }
        }
    }
    $stmt = $conn->prepare("UPDATE product SET ProductName = ?, Description = ?, Price = ?, Stock = ?, Status = ?, ImageURL = ? WHERE ProductID = ?");
    $stmt->bind_param("ssdisss", $productName, $description, $price, $stock, $status, $imageURL, $productID);
   
    if ($stmt->execute()) {
        echo '<script>alert("Product updated successfully!"); window.location = "manage_products.php";</script>';
    } else {
        echo '<script>alert("Error updating product.");</script>';
    }
    $stmt->close();
}
// Handle Delete Product
if (isset($_GET['delete'])) {
    $productID = intval($_GET['delete']);
   
    // Get image path before deleting
    $result = $conn->query("SELECT ImageURL FROM product WHERE ProductID = $productID");
    if ($result && $row = $result->fetch_assoc()) {
        if (!empty($row['ImageURL']) && file_exists('../' . $row['ImageURL'])) {
            unlink('../' . $row['ImageURL']);
        }
    }
   
    $conn->query("DELETE FROM product WHERE ProductID = $productID");
    echo '<script>window.location = "manage_products.php";</script>';
    exit();
}
// Fetch all products
$products = $conn->query("SELECT * FROM product ORDER BY ProductID DESC");

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = " . $_SESSION['userID'] . " AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;
            --abyss: #030f1e;
            --ocean: #041e35;
            --navy:  #0a2d4a;
            --teal:  #0077b6;
            --aqua:  #00b4d8;
            --cyan:  #48cae4;
            --glow:  #90e0ef;
            --foam:  #caf0f8;
            --white: #f0f9ff;
            --gold:  #f4c842;
            --glass: rgba(0,180,216,0.08);
            --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            color: var(--white);
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            width: var(--sidebar-w);
            background: var(--abyss);
            border-right: 1px solid var(--glass-border);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            padding: 24px 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 40px; height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(0,180,216,0.35);
            box-shadow: 0 0 14px rgba(0,180,216,0.2);
        }

        .sidebar-logo span {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.05rem;
            font-weight: 500;
            color: var(--white);
            line-height: 1.2;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 16px 12px 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(72,202,228,0.15) transparent;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }

        .nav-section-label {
            font-size: 0.62rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.25);
            padding: 16px 12px 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 10px;
            color: rgba(202,240,248,0.5) !important;
            text-decoration: none;
            font-size: 0.87rem;
            font-weight: 500;
            transition: all 0.25s ease;
            margin-bottom: 2px;
            position: relative;
        }

        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 0.9rem;
            color: rgba(0,180,216,0.4);
            transition: color 0.25s;
        }

        .nav-link:hover {
            background: var(--glass);
            color: var(--foam) !important;
        }

        .nav-link:hover i { color: var(--aqua); }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12));
            border: 1px solid rgba(0,180,216,0.2);
            color: var(--aqua) !important;
        }

        .nav-link.active i { color: var(--aqua); }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0; top: 20%; bottom: 20%;
            width: 3px;
            background: var(--aqua);
            border-radius: 0 3px 3px 0;
        }

        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }

        /* ── MAIN ── */
        .main-content {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            padding: 28px 32px;
            transition: margin-left 0.3s ease;
        }

        /* ── TOP BAR ── */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .topbar-greeting h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.7rem;
            font-weight: 400;
            color: var(--white);
            line-height: 1.1;
        }

        .topbar-greeting p {
            font-size: 0.82rem;
            color: rgba(202,240,248,0.4);
            margin-top: 2px;
        }

        .topbar-actions { display: flex; align-items: center; gap: 12px; }

        .topbar-btn {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: rgba(202,240,248,0.6);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
        }

        .topbar-btn:hover {
            background: rgba(0,180,216,0.15);
            border-color: var(--aqua);
            color: var(--aqua);
        }

        .topbar-notif-badge {
            position: absolute;
            top: -3px; right: -3px;
            background: var(--gold);
            color: var(--deep);
            font-size: 0.58rem;
            font-weight: 700;
            min-width: 16px;
            height: 16px;
            border-radius: 50px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .avatar-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            padding: 6px 14px 6px 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .avatar-btn:hover {
            border-color: rgba(0,180,216,0.35);
            background: rgba(0,180,216,0.1);
        }

        .avatar-circle {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            font-weight: 700;
            font-size: 0.85rem;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

        .avatar-name {
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--white);
        }

        .avatar-role {
            font-size: 0.7rem;
            color: rgba(202,240,248,0.4);
        }

        /* ── ADMIN CARDS ── */
        .admin-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 28px;
        }

        .section-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.35rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 20px;
        }

        /* Table visibility */
        .table {
            color: var(--foam) !important;
        }
        
        .table thead th {
            color: var(--white) !important;
            font-weight: 600;
            background: rgba(4,30,53,0.8) !important;
        }
        
        .table tbody tr {
            border-color: var(--glass-border) !important;
        }
        
        .table tbody td {
            color: var(--foam) !important;
        }
        
        .table tbody tr:hover {
            background: rgba(0,180,216,0.05) !important;
        }

        .badge {
            font-weight: 500;
            padding: 6px 12px;
        }

        .badge.bg-warning { color: #1e293b !important; }
        .badge.bg-info { color: #fff !important; }
        .badge.bg-success { color: #fff !important; }
        .badge.bg-danger { color: #fff !important; }
        .badge.bg-primary { color: #fff !important; }
        .badge.bg-secondary { color: #fff !important; }

        .text-muted { color: rgba(202,240,248,0.5) !important; }

        /* Product image */
        .product-img { width: 60px; height: 60px; object-fit: cover; border-radius: 10px; border: 1px solid var(--glass-border); }
        .product-img-placeholder { width: 60px; height: 60px; background: rgba(4,30,53,0.6); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: rgba(202,240,248,0.3); border: 1px solid var(--glass-border); }
        .image-preview { max-width: 200px; max-height: 200px; border-radius: 10px; border: 2px solid var(--glass-border); }

        /* Modal */
        .modal-content {
            background: var(--ocean);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
        }

        .modal-header {
            border-bottom: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .modal-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 500;
        }

        .modal-footer {
            border-top: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .form-control, .form-select {
            background: rgba(4,30,53,0.6);
            border: 1px solid var(--glass-border);
            color: var(--white);
            border-radius: 10px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--aqua);
            box-shadow: 0 0 0 0.2rem rgba(0,180,216,0.15);
            background: rgba(4,30,53,0.8);
        }

        .form-label {
            color: rgba(202,240,248,0.7);
            font-weight: 500;
        }

        /* Mobile */
        @media (max-width: 991px) {
            .main-content { margin-left: 0; padding: 20px 18px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .admin-card { padding: 18px; }
        }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <span>De Chavez<br>Waterhaus</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php" class="nav-link">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="manage_products.php" class="nav-link active">
            <i class="fas fa-box"></i> Manage Products
        </a>
        <a href="manage_orders.php" class="nav-link">
            <i class="fas fa-shopping-cart"></i> Manage Orders
        </a>
        <a href="manage_users.php" class="nav-link">
            <i class="fas fa-users"></i> Manage Users
        </a>
        <a href="manage_employees.php" class="nav-link">
            <i class="fas fa-user-tie"></i> Manage Employees
        </a>

        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link">
            <i class="fas fa-clock"></i> Attendance
        </a>
        <a href="payroll_management.php" class="nav-link">
            <i class="fas fa-money-bill"></i> Payroll
        </a>
        <a href="generate_payslip.php" class="nav-link">
            <i class="fas fa-file-pdf"></i> Generate Payslip
        </a>
        <a href="leave_management.php" class="nav-link">
            <i class="fas fa-calendar-alt"></i> Manage Leave
        </a>

        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php" class="nav-link">
            <i class="fas fa-headset"></i> Support Tickets
        </a>
        <a href="reports.php" class="nav-link">
            <i class="fas fa-chart-bar"></i> Reports & Analytics
        </a>

        <div class="nav-section-label" style="margin-top: 16px;"></div>
        <a href="profile.php" class="nav-link">
            <i class="fas fa-user"></i> My Profile
        </a>
        <a href="../logout.php" class="nav-link danger">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN CONTENT ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle d-lg-none" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-greeting">
                <h4>Manage Products</h4>
                <p>Add, edit, and manage your product inventory with images</p>
            </div>
        </div>

        <div class="topbar-actions">
            <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="fas fa-plus me-2"></i> Add Product
            </button>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                        <img src="../<?php echo htmlspecialchars($admin['profile_picture']); ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <div class="avatar-circle">
                            <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($adminName); ?></div>
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

    <!-- Products Table -->
    <div class="admin-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="section-title mb-0">All Products</h5>
            <span class="text-muted"><?php echo $products->num_rows; ?> products</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Image</th>
                        <th>Product</th>
                        <th>Description</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products->num_rows > 0): ?>
                        <?php while ($prod = $products->fetch_assoc()) { ?>
                            <tr>
                                <td class="ps-4">
                                    <?php if (!empty($prod['ImageURL']) && file_exists('../' . $prod['ImageURL'])): ?>
                                        <img src="../<?php echo $prod['ImageURL']; ?>" alt="<?php echo $prod['ProductName']; ?>" class="product-img">
                                    <?php else: ?>
                                        <div class="product-img-placeholder">
                                            <i class="fas fa-image fa-2x"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($prod['ProductName']); ?></div>
                                </td>
                                <td class="text-muted small"><?php echo substr(htmlspecialchars($prod['Description']), 0, 50); ?>...</td>
                                <td class="fw-bold">₱<?php echo number_format($prod['Price'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $prod['Stock'] > 10 ? 'success' : ($prod['Stock'] > 0 ? 'warning' : 'danger'); ?>">
                                        <?php echo $prod['Stock']; ?> units
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $prod['Status'] == 'Active' ? 'success' : 'secondary'; ?>">
                                        <?php echo $prod['Status']; ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-glass" data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $prod['ProductID']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="manage_products.php?delete=<?php echo $prod['ProductID']; ?>" class="btn btn-sm btn-glass text-danger" onclick="return confirm('Delete this product?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <!-- Edit Modal -->
                            <div class="modal fade" id="editProductModal<?php echo $prod['ProductID']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold">Edit Product</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="productID" value="<?php echo $prod['ProductID']; ?>">
                                            <input type="hidden" name="current_image" value="<?php echo $prod['ImageURL']; ?>">
                                            <div class="modal-body p-4">
                                                <div class="row">
                                                    <div class="col-md-4 text-center mb-3">
                                                        <?php if (!empty($prod['ImageURL']) && file_exists('../' . $prod['ImageURL'])): ?>
                                                            <img src="../<?php echo $prod['ImageURL']; ?>" alt="Current" class="image-preview mb-2">
                                                        <?php else: ?>
                                                            <div class="product-img-placeholder mb-2" style="width: 150px; height: 150px; margin: 0 auto;">
                                                                <i class="fas fa-image fa-3x"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <label class="form-label small">Change Image</label>
                                                        <input type="file" class="form-control form-control-sm" name="product_image" accept="image/*">
                                                    </div>
                                                    <div class="col-md-8">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Product Name</label>
                                                            <input type="text" class="form-control" name="product_name" value="<?php echo $prod['ProductName']; ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Description</label>
                                                            <textarea class="form-control" name="description" rows="3" required><?php echo $prod['Description']; ?></textarea>
                                                        </div>
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-semibold">Price (₱)</label>
                                                                <input type="number" step="0.01" min="0.01" max="99999.99" class="form-control" name="price" value="<?php echo $prod['Price']; ?>" required>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-semibold">Stock</label>
                                                                <input type="number" min="0" max="99999" class="form-control" name="stock" value="<?php echo $prod['Stock']; ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="mt-3">
                                                            <label class="form-label fw-semibold">Status</label>
                                                            <select class="form-select" name="status" required>
                                                                <option value="Active" <?php echo $prod['Status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                                                <option value="Inactive" <?php echo $prod['Status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="update_product" class="btn btn-primary px-5">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-box fa-3x mb-3 opacity-50"></i>
                                <p>No products yet. Add your first product!</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Add New Product</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="product-img-placeholder mb-2" style="width: 150px; height: 150px; margin: 0 auto;">
                                <i class="fas fa-image fa-3x"></i>
                            </div>
                            <label class="form-label small">Product Image (Optional)</label>
                            <input type="file" class="form-control form-control-sm" name="product_image" accept="image/*">
                            <small class="text-muted">JPG, PNG, WebP</small>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Product Name</label>
                                <input type="text" class="form-control" name="product_name" placeholder="e.g. Premium 5-Gallon Water" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Product description..." required></textarea>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Price (₱)</label>
                                    <input type="number" step="0.01" min="0.01" max="99999.99" class="form-control" name="price" placeholder="0.00" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Initial Stock</label>
                                    <input type="number" min="0" max="99999" class="form-control" name="stock" value="50" required>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="form-label fw-semibold">Status</label>
                                <select class="form-select" name="status" required>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_product" class="btn btn-primary px-5">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mobile Sidebar
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('mobileToggle');

    function openSidebar() { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle) toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) closeSidebar();
        });
    });
</script>
</body>
</html> 