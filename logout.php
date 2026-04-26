<?php
session_start();

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
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0077B6 0%, #023E8A 100%);
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
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://images.unsplash.com/photo-1548839140-29a749e1cf4d?auto=format&fit=crop&w=2070&q=80') center/cover no-repeat;
            opacity: 0.12;
            z-index: 0;
        }
        
        .logout-card {
            background: rgba(255,255,255,0.97);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4);
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
            background: linear-gradient(135deg, #0077B6, #023E8A);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2.5rem;
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
            color: #6c757d;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .btn-back:hover {
            color: #495057;
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <div class="logout-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        
        <h3 class="fw-bold mb-3">Logging Out</h3>
        <p class="text-muted mb-4">Are you sure you want to end your session?</p>
        
        <form method="POST" action="logout.php">
            <button type="submit" class="btn btn-logout w-100 py-3 rounded-pill mb-3">
                <i class="fas fa-sign-out-alt me-2"></i> Yes, Log Me Out
            </button>
        </form>
        
        <a href="index.php" class="btn-back">
            <i class="fas fa-arrow-left me-1"></i> No, Go Back to Home
        </a>
        
        <div class="mt-4 pt-3 border-top">
            <small class="text-muted">Thank you for using De Chavez Waterhaus</small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>