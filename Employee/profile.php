<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    echo '<script>alert("Access denied. Employees only."); window.location = "../login.php";</script>';
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch employee data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_picture']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $newname = 'employee_' . $userID . '_' . time() . '.' . $ext;
            $upload_path = '../uploads/profile_pictures/' . $newname;
            
            if (!is_dir('../uploads/profile_pictures/')) {
                mkdir('../uploads/profile_pictures/', 0777, true);
            }
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])) {
                    unlink('../' . $employee['profile_picture']);
                }
                
                $update = $conn->prepare("UPDATE customers SET profile_picture = ? WHERE userID = ?");
                $db_path = 'uploads/profile_pictures/' . $newname;
                $update->bind_param("si", $db_path, $userID);
                $update->execute();
                $update->close();
                
                echo '<script>alert("Profile picture updated!"); window.location = "profile.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Invalid file type. Only JPG, PNG, GIF allowed.");</script>';
        }
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = trim(htmlspecialchars($_POST['firstname']));
    $lastname = trim(htmlspecialchars($_POST['lastname']));
    $email = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
    $phone = trim(htmlspecialchars($_POST['phone']));
    
    $errors = [];
    
    if (empty($firstname)) $errors[] = "First name is required";
    if (empty($lastname)) $errors[] = "Last name is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    
    if (!empty($errors)) {
        echo '<script>alert("' . implode('\\n', $errors) . '");</script>';
    } else {
        $update = $conn->prepare("UPDATE customers SET Firstname = ?, Lastname = ?, Email = ?, Contact = ? WHERE userID = ?");
        $update->bind_param("ssssi", $firstname, $lastname, $email, $phone, $userID);
        
        if ($update->execute()) {
            $_SESSION['userName'] = $firstname . ' ' . $lastname;
            echo '<script>alert("Profile updated successfully!"); window.location = "profile.php";</script>';
            exit();
        } else {
            echo '<script>alert("Error updating profile.");</script>';
        }
        $update->close();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    
    if (!password_verify($current, $employee['Password'])) {
        echo '<script>alert("Current password is incorrect.");</script>';
    } elseif ($new !== $confirm) {
        echo '<script>alert("New passwords do not match.");</script>';
    } elseif (strlen($new) < 8) {
        echo '<script>alert("New password must be at least 8 characters.");</script>';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
        $update->bind_param("si", $hashed, $userID);
        $update->execute();
        $update->close();
        
        echo '<script>alert("Password changed successfully!"); window.location = "profile.php";</script>';
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile • Employee</title>
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
        
        .nav-menu { flex: 1; overflow-y: auto; padding-bottom: 20px; }
        .logout-section { padding: 15px 10px; border-top: 1px solid #eee; background: white; }
        
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        .profile-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        
        .profile-header {
            background: linear-gradient(135deg, #0077B6 0%, #023E8A 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 40px 30px;
            text-align: center;
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
                <small class="d-block text-muted">Employee Portal</small>
            </div>
        </div>
        
        <div class="nav-menu px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="employee_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="my_deliveries.php" class="nav-link"><i class="fas fa-truck me-3"></i> <span>My Deliveries</span></a></li>
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
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">My Profile</h4>
                    <p class="text-muted mb-0">Manage your account information</p>
                </div>
            </div>
            
            <div class="dropdown">
                <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                    <?php if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])): ?>
                        <img src="../<?php echo $employee['profile_picture']; ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <span class="fw-bold fs-6"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="text-start d-none d-md-block">
                        <div class="fw-semibold"><?php echo htmlspecialchars($userName); ?></div>
                        <small class="text-muted">Employee</small>
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
            <!-- Profile Card -->
            <div class="col-lg-4">
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="mb-3">
                            <?php if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])): ?>
                                <img src="../<?php echo $employee['profile_picture']; ?>" alt="Profile" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid rgba(255,255,255,0.3);">
                            <?php else: ?>
                                <div class="bg-white bg-opacity-25 rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 120px; height: 120px;">
                                    <span class="fw-bold fs-1"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($userName); ?></h5>
                        <p class="mb-0 opacity-75">Employee</p>
                        
                        <button class="btn btn-light btn-sm mt-3 px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
                            <i class="fas fa-camera me-2"></i> Change Photo
                        </button>
                    </div>
                    
                    <div class="p-4">
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted">Member Since</span>
                            <span class="fw-semibold"><?php echo date('M Y', strtotime($employee['created_at'])); ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted">Status</span>
                            <span class="badge bg-success">Active</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2">
                            <span class="text-muted">Role</span>
                            <span class="fw-semibold">Delivery Staff</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Edit Profile Form -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="fw-bold mb-0">Personal Information</h6>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">First Name</label>
                                    <input type="text" class="form-control" name="firstname" value="<?php echo htmlspecialchars($employee['Firstname']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Last Name</label>
                                    <input type="text" class="form-control" name="lastname" value="<?php echo htmlspecialchars($employee['Lastname']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Email Address</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($employee['Email']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($employee['Contact'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="update_profile" class="btn btn-primary px-5">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Change Password -->
                <div class="card border-0 shadow-sm mt-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="fw-bold mb-0">Change Password</h6>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">New Password</label>
                                    <input type="password" class="form-control" name="new_password" minlength="8" required>
                                    <small class="text-muted">Min 8 characters</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="change_password" class="btn btn-outline-primary px-5">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Photo Modal -->
    <div class="modal fade" id="uploadPhotoModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Upload Profile Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        <div class="text-center mb-3">
                            <?php if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])): ?>
                                <img src="../<?php echo $employee['profile_picture']; ?>" alt="Current" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #f0f0f0;">
                            <?php else: ?>
                                <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 120px; height: 120px;">
                                    <i class="fas fa-user fa-4x text-muted"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select New Photo</label>
                            <input type="file" class="form-control" name="profile_picture" accept="image/*" required>
                            <small class="text-muted">Allowed: JPG, PNG, GIF (Max 2MB)</small>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_photo" class="btn btn-primary px-5">Upload Photo</button>
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
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
    </script>
</body>
</html>