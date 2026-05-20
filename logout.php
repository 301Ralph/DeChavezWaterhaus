<?php
session_start();

$backLink = "index.php";
if (isset($_SESSION['userID']) && isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
    if ($role === 'customer')     $backLink = "Customer/customer_dashboard.php";
    elseif ($role === 'admin')    $backLink = "Admin/admin_dashboard.php";
    elseif ($role === 'employee') $backLink = "Employee/employee_dashboard.php";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = array();
    session_destroy();
    echo '<script>alert("You have been logged out successfully."); window.location="login.php";</script>';
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
:root{--deep:#020d18;--abyss:#030f1e;--ocean:#041e35;--teal:#0077b6;--aqua:#00b4d8;--cyan:#48cae4;--foam:#caf0f8;--white:#f0f9ff;--gold:#f4c842;--glass:rgba(0,180,216,0.08);--glass-border:rgba(72,202,228,0.18);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--deep);min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
body::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 25% 25%,rgba(0,180,216,.09) 0%,transparent 55%),radial-gradient(circle at 75% 75%,rgba(72,202,228,.06) 0%,transparent 55%);z-index:0;animation:orb 8s ease-in-out infinite alternate;}
@keyframes orb{from{opacity:.7;transform:scale(1);}to{opacity:1;transform:scale(1.04);}}
.ring{position:absolute;border-radius:50%;border:1px solid rgba(72,202,228,.07);animation:expand 6s ease-out infinite;z-index:1;}
.ring:nth-child(1){width:200px;height:200px;animation-delay:0s;}
.ring:nth-child(2){width:340px;height:340px;animation-delay:1.5s;}
.ring:nth-child(3){width:480px;height:480px;animation-delay:3s;}
@keyframes expand{0%{transform:scale(.8);opacity:.6;}100%{transform:scale(1.3);opacity:0;}}
.logout-card{background:linear-gradient(145deg,rgba(10,45,74,.88),rgba(3,15,30,.96));border:1px solid var(--glass-border);border-radius:26px;box-shadow:0 30px 60px -12px rgba(0,0,0,.6),0 0 0 1px rgba(72,202,228,.04);padding:3.2rem 2.8rem;text-align:center;max-width:430px;width:100%;position:relative;z-index:2;backdrop-filter:blur(16px);}
.card-logo{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid rgba(0,180,216,.3);box-shadow:0 0 20px rgba(0,180,216,.2);margin-bottom:1.4rem;}
.logout-icon{width:88px;height:88px;background:linear-gradient(135deg,var(--teal),var(--aqua));border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.6rem;color:var(--deep);font-size:2.2rem;box-shadow:0 12px 32px rgba(0,180,216,.45);position:relative;}
.logout-icon::after{content:'';position:absolute;inset:-6px;border-radius:50%;border:2px solid rgba(0,180,216,.25);animation:pulse-ring 2.4s ease-out infinite;}
@keyframes pulse-ring{0%{transform:scale(1);opacity:.6;}100%{transform:scale(1.35);opacity:0;}}
.logout-title{font-family:'Cormorant Garamond',serif;font-size:1.9rem;font-weight:400;color:var(--white);letter-spacing:.02em;margin-bottom:.5rem;}
.logout-sub{font-size:.88rem;color:rgba(202,240,248,.45);margin-bottom:2rem;line-height:1.6;}
.btn-logout{width:100%;padding:14px;background:linear-gradient(135deg,#c0392b,#e74c3c);border:none;border-radius:50px;color:#fff;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:all .3s ease;box-shadow:0 6px 20px rgba(231,76,60,.35);margin-bottom:14px;}
.btn-logout:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(231,76,60,.5);}
.btn-logout:active{transform:translateY(0);}
.btn-back{display:inline-flex;align-items:center;gap:6px;color:rgba(202,240,248,.48);text-decoration:none;font-size:.86rem;font-weight:500;transition:color .25s ease;padding:8px 18px;border-radius:50px;border:1px solid transparent;}
.btn-back:hover{color:var(--aqua);border-color:rgba(0,180,216,.2);background:rgba(0,180,216,.06);}
.card-footer-text{margin-top:1.6rem;padding-top:1.2rem;border-top:1px solid var(--glass-border);font-size:.76rem;color:rgba(202,240,248,.28);letter-spacing:.04em;}
</style>
</head>
<body>
<div class="ring"></div>
<div class="ring"></div>
<div class="ring"></div>

<div class="logout-card">
    <img src="images/logo.jpg" alt="De Chavez Waterhaus" class="card-logo">
    <div class="logout-icon"><i class="fas fa-sign-out-alt"></i></div>
    <h3 class="logout-title">Logging Out</h3>
    <p class="logout-sub">Are you sure you want to end your current session?</p>
    <form method="POST" action="logout.php">
        <button type="submit" class="btn-logout"><i class="fas fa-sign-out-alt me-2"></i> Yes, Log Me Out</button>
    </form>
    <a href="<?php echo htmlspecialchars($backLink); ?>" class="btn-back">
        <i class="fas fa-arrow-left"></i> No, take me back
    </a>
    <div class="card-footer-text">Thank you for using De Chavez Waterhaus</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>