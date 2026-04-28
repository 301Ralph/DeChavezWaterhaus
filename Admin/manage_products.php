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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products • Admin</title>
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
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        
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
        
        .product-img { width: 60px; height: 60px; object-fit: cover; border-radius: 10px; border: 1px solid #eee; }
        .product-img-placeholder { width: 60px; height: 60px; background: #f0f0f0; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #999; }
        .image-preview { max-width: 200px; max-height: 200px; border-radius: 10px; border: 2px solid #eee; }
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
                <li class="nav-item"><a href="manage_products.php" class="nav-link active"><i class="fas fa-box me-3"></i> <span>Manage Products</span></a></li>
                <li class="nav-item"><a href="manage_orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Manage Orders</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Employees</span></a></li>
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
        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">Manage Products</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Add, edit, and manage your product inventory with images</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus me-2"></i> Add Product
                </button>
                
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

        <!-- Products Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
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
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $prod['ProductID']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="manage_products.php?delete=<?php echo $prod['ProductID']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this product?')">
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
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                                                    <div class="modal-footer border-0 p-4 pt-0">
                                                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
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
        </div>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_product" class="btn btn-primary px-5">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
            });
            
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 992 && !sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>