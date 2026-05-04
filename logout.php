<?php
session_start();

// Determine where to redirect back based on user role
$backLink = "index.php"; // Default fallback

if (isset($_SESSION['userID']) && isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
    
    if ($role === 'customer') {
        $backLink = "Customer/customer_dashboard.php";
    } elseif ($role === 'admin') {
        $backLink = "Admin/admin_dashboard.php";
    } elseif ($role === 'employee') {
        $backLink = "Employee/employee_dashboard.php";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear the session array
    $_SESSION = array();

    // Destroy the session
    session_destroy();

    // Redirect to login with message
    echo '<script>
        alert("You have been logged out successfully.");
        window.location = "login.php";
    </script>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="images/logo.jpg" type="image/x-icon">
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
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 20%, rgba(0,180,216,0.08) 0%, transparent 50%),
                        radial-gradient(circle at 70% 80%, rgba(72,202,228,0.06) 0%, transparent 50%);
            z-index: 0;
        }

        .logout-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.85), rgba(3,15,30,0.95));
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            padding: 3rem 2.5rem;
            text-align: center;
            max-width: 420px;
            width: 100%;
            position: relative;
            z-index: 2;
        }

        .logout-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: var(--deep);
            font-size: 2.5rem;
            box-shadow: 0 10px 30px rgba(0,180,216,0.4);
        }

        .btn-logout {
            background: linear-gradient(135deg, #dc3545, #b02a37);
            border: none;
            color: white;
            font-weight: 600;
            padding: 14px 0;
            font-size: 1.05rem;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(220, 53, 69, 0.3);
        }

        .btn-back {
            color: rgba(202,240,248,0.6);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .btn-back:hover {
            color: var(--foam);
        }

        .text-muted-custom {
            color: rgba(202,240,248,0.5);
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <div class="logout-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        
        <h3 class="fw-bold mb-3" style="color: var(--white); font-family: 'Cormorant Garamond', serif;">Logging Out</h3>
        <p class="text-muted-custom mb-4">Are you sure you want to end your session?</p>
        
        <form method="POST" action="logout.php">
            <button type="submit" class="btn btn-logout w-100 py-3 rounded-pill mb-3">
                <i class="fas fa-sign-out-alt me-2"></i> Yes, Log Me Out
            </button>
        </form>
        
        <a href="<?php echo $backLink; ?>" class="btn-back">
            <i class="fas fa-arrow-left me-1"></i> No, Go Back to Home
        </a>
        
        <div class="mt-4 pt-3" style="border-top: 1px solid var(--glass-border);">
            <small class="text-muted-custom">Thank you for using De Chavez Waterhaus</small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>