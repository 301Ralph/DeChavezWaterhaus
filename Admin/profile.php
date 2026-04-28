<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Create uploads directory if not exists
$uploadDir = '../uploads/profile_pictures/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Fetch admin data
$admin = $conn->query("SELECT * FROM customers WHERE userID = $adminID")->fetch_assoc();

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = htmlspecialchars($_POST['firstname']);
    $lastname = htmlspecialchars($_POST['lastname']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $contact = htmlspecialchars($_POST['contact']);
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo '<script>alert("Invalid email format."); window.location = "profile.php";</script>';
        exit();
    }
    
    // Check if email already exists (excluding current user)
    $emailCheck = $conn->prepare("SELECT userID FROM customers WHERE Email = ? AND userID != ?");
    $emailCheck->bind_param("si", $email, $adminID);
    $emailCheck->execute();
    if ($emailCheck->get_result()->num_rows > 0) {
        echo '<script>alert("Email already in use by another account."); window.location = "profile.php";</script>';
        exit();
    }
    $emailCheck->close();
    
    // Handle profile picture upload
    $profilePicture = $admin['profile_picture'] ?? '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $fileType = $_FILES['profile_picture']['type'];
        
        if (in_array($fileType, $allowedTypes)) {
            $fileName = 'admin_' . $adminID . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                // Delete old picture if exists
                if (!empty($profilePicture) && file_exists('../' . $profilePicture)) {
                    unlink('../' . $profilePicture);
                }
                $profilePicture = 'uploads/profile_pictures/' . $fileName;
            }
        }
    }
    
    // Update profile
    $stmt = $conn->prepare("UPDATE customers SET Firstname = ?, Lastname = ?, Email = ?, Contact = ?, profile_picture = ? WHERE userID = ?");
    $stmt->bind_param("sssssi", $firstname, $lastname, $email, $contact, $profilePicture, $adminID);
    
    if ($stmt->execute()) {
        $_SESSION['userName'] = $firstname . ' ' . $lastname;
        echo '<script>alert("Profile updated successfully!"); window.location = "profile.php";</script>';
    } else {
        echo '<script>alert("Error updating profile."); window.location = "profile.php";</script>';
    }
    $stmt->close();
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate new password
    if (strlen($newPassword) < 8) {
        echo '<script>alert("Password must be at least 8 characters long."); window.location = "profile.php";</script>';
        exit();
    }
    
    if (!preg_match('/[A-Z]/', $newPassword)) {
        echo '<script>alert("Password must contain at least one uppercase letter."); window.location = "profile.php";</script>';
        exit();
    }
    
    if (!preg_match('/[0-9]/', $newPassword)) {
        echo '<script>alert("Password must contain at least one number."); window.location = "profile.php";</script>';
        exit();
    }
    
    if ($newPassword !== $confirmPassword) {
        echo '<script>alert("New passwords do not match."); window.location = "profile.php";</script>';
        exit();
    }
    
    // Verify current password
    if (!password_verify($currentPassword, $admin['Password'])) {
        echo '<script>alert("Current password is incorrect."); window.location = "profile.php";</script>';
        exit();
    }
    
    // Hash new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password
    $stmt = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
    $stmt->bind_param("si", $hashedPassword, $adminID);
    
    if ($stmt->execute()) {
        echo '<script>alert("Password changed successfully!"); window.location = "profile.php";</script>';
    } else {
        echo '<script>alert("Error changing password."); window.location = "profile.php";</script>';
    }
    $stmt->close();
}

// Handle Remove Profile Picture
if (isset($_GET['remove_photo'])) {
    if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])) {
        unlink('../' . $admin['profile_picture']);
    }
    
    $stmt = $conn->prepare("UPDATE customers SET profile_picture = NULL WHERE userID = ?");
    $stmt->bind_param("i", $adminID);
    $stmt->execute();
    $stmt->close();
    
    echo '<script>window.location = "profile.php";</script>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile • Admin</title>
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

        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        .profile-avatar {
            width: 150px; height: 150px; border-radius: 50%; 
            object-fit: cover; border: 5px solid #fff; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .profile-avatar-placeholder {
            width: 150px; height: 150px; border-radius: 50%; 
            background: linear-gradient(135deg, #0077B6, #023E8A);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 3rem; font-weight: bold;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
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
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar me-3"></i> <span>Reports & Analytics</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link active"><i class="fas fa-user me-3"></i> <span>My Profile</span></a></li>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">My Profile</h4>
                <p class="text-muted mb-0">Manage your account information</p>
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

        <div class="row g-4">
            <!-- Profile Overview -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4 text-center">
                        <div class="mb-4">
                            <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                                <img src="../<?php echo $admin['profile_picture']; ?>" alt="Profile" class="profile-avatar">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder">
                                    <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($admin['Firstname'] . ' ' . $admin['Lastname']); ?></h5>
                        <span class="badge bg-primary px-3 py-2 mb-3">Administrator</span>
                        
                        <div class="d-grid gap-2 mt-4">
                            <?php if (!empty($admin['profile_picture'])): ?>
                                <a href="profile.php?remove_photo=1" class="btn btn-outline-danger btn-sm" onclick="return confirm('Remove profile picture?')">
                                    <i class="fas fa-trash me-1"></i> Remove Photo
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Edit Profile Information</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">First Name</label>
                                    <input type="text" class="form-control" name="firstname" value="<?php echo htmlspecialchars($admin['Firstname']); ?>" required pattern="[A-Za-z\s]+" title="Only letters and spaces allowed">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Last Name</label>
                                    <input type="text" class="form-control" name="lastname" value="<?php echo htmlspecialchars($admin['Lastname']); ?>" required pattern="[A-Za-z\s]+" title="Only letters and spaces allowed">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Email Address</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin['Email']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Phone Number</label>
                                    <input type="tel" class="form-control" name="contact" value="<?php echo htmlspecialchars($admin['Contact'] ?? ''); ?>" pattern="[0-9+\-\s]+" title="Only numbers, +, - and spaces allowed">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Profile Picture</label>
                                    <input type="file" class="form-control" name="profile_picture" accept="image/jpeg,image/png,image/jpg,image/webp">
                                    <small class="text-muted">JPG, PNG, WebP • Max 2MB</small>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="update_profile" class="btn btn-primary px-5">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Change Password</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" id="passwordForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Current Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="current_password" id="current_password" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="new_password" id="new_password" required minlength="8" pattern="(?=.*[A-Z])(?=.*[0-9]).{8,}" title="At least 8 characters with 1 uppercase and 1 number">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Min 8 chars, 1 uppercase, 1 number</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="change_password" class="btn btn-warning px-5">
                                    <i class="fas fa-key me-2"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.createElement('button');
        mobileToggle.className = 'btn btn-light d-lg-none position-fixed shadow-sm';
        mobileToggle.style.cssText = 'top: 22px; left: 22px; z-index: 1100; border-radius: 12px;';
        mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(mobileToggle);
        mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));

        // Password visibility toggle
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = event.currentTarget.querySelector('i') || event.target;
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password confirmation validation
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                alert('New passwords do not match!');
                return false;
            }
            
            if (newPass.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
            
            if (!/[A-Z]/.test(newPass)) {
                e.preventDefault();
                alert('Password must contain at least one uppercase letter!');
                return false;
            }
            
            if (!/[0-9]/.test(newPass)) {
                e.preventDefault();
                alert('Password must contain at least one number!');
                return false;
            }
        });
    </script>
</body>
</html>