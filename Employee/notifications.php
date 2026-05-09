<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    echo '<script>alert("Access denied. Employees only."); window.location = "../login.php";</script>';
    exit();
}

$userID   = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Mark all as read
$conn->query("UPDATE notifications SET is_read = 1 WHERE userID = $userID");

// Fetch notifications
$notifs     = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 50");
$totalCount = $notifs->num_rows;

// Fetch employee data for avatar
$stmt = $conn->prepare("SELECT profile_picture FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

$firstName = explode(' ', $userName)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 24px 22px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); box-shadow: 0 0 14px rgba(0,180,216,0.2); }
        .sidebar-logo-text { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-logo-sub  { font-size: 0.68rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 16px 12px 16px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.62rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.25); padding: 16px 12px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 10px; color: rgba(202,240,248,0.5) !important; text-decoration: none; font-size: 0.87rem; font-weight: 500; transition: all 0.25s ease; margin-bottom: 2px; position: relative; }
        .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; color: rgba(0,180,216,0.4); transition: color 0.25s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .sidebar-footer { padding: 14px 12px; border-top: 1px solid var(--glass-border); flex-shrink: 0; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 28px 32px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; }
        .topbar-left h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-left p { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .avatar-btn { display: flex; align-items: center; gap: 10px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 6px 14px 6px 6px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.82rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.7rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 14px !important; padding: 8px !important; box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 8px !important; padding: 9px 14px !important; font-size: 0.84rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08));
            border: 1px solid rgba(0,180,216,0.2);
            border-radius: 18px; padding: 22px 26px; margin-bottom: 24px;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 14px;
        }

        .ph-icon { width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; box-shadow: 0 6px 18px rgba(0,180,216,0.28); }
        .ph-title { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 400; color: var(--white); }
        .ph-sub   { font-size: 0.8rem; color: rgba(202,240,248,0.4); margin-top: 2px; }

        .count-pill { display: inline-flex; align-items: center; gap: 8px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 7px 16px; font-size: 0.8rem; color: rgba(202,240,248,0.55); }
        .count-pill strong { color: var(--aqua); font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; }

        /* ── FILTER TABS ── */
        .filter-tabs { display: flex; gap: 7px; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-tab { padding: 7px 16px; border-radius: 50px; border: 1px solid var(--glass-border); background: transparent; color: rgba(202,240,248,0.42); font-family: 'DM Sans', sans-serif; font-size: 0.78rem; font-weight: 500; cursor: pointer; transition: all 0.25s; }
        .filter-tab:hover { color: var(--foam); border-color: rgba(0,180,216,0.28); }
        .filter-tab.active { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: transparent; color: var(--deep); font-weight: 700; box-shadow: 0 4px 14px rgba(0,180,216,0.25); }

        /* ── NOTIFICATION ITEMS ── */
        .notif-list { display: flex; flex-direction: column; gap: 0; }

        .date-divider { font-size: 0.68rem; letter-spacing: 0.18em; text-transform: uppercase; color: rgba(202,240,248,0.25); padding: 14px 4px 8px; display: flex; align-items: center; gap: 12px; }
        .date-divider::after { content: ''; flex: 1; height: 1px; background: var(--glass-border); }

        .notif-item {
            display: flex; align-items: flex-start; gap: 14px;
            background: linear-gradient(145deg, rgba(10,45,74,0.5), rgba(3,15,30,0.72));
            border: 1px solid var(--glass-border);
            border-left: 3px solid rgba(0,180,216,0.25);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            animation: slideIn 0.4s ease both;
            position: relative;
        }

        .notif-item:hover { transform: translateX(4px); border-color: rgba(0,180,216,0.28); background: linear-gradient(145deg, rgba(10,45,74,0.65), rgba(3,15,30,0.82)); }

        @keyframes slideIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        .notif-item:nth-child(1) { animation-delay:0.04s; }
        .notif-item:nth-child(2) { animation-delay:0.08s; }
        .notif-item:nth-child(3) { animation-delay:0.12s; }
        .notif-item:nth-child(n+4) { animation-delay:0.16s; }

        /* icon wrap */
        .notif-icon { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0; }
        .ni-clock    { background: rgba(0,180,216,0.1);   color: var(--aqua); }
        .ni-truck    { background: rgba(74,222,128,0.1);  color: var(--green); }
        .ni-alert    { background: rgba(244,200,66,0.1);  color: var(--gold); }
        .ni-pay      { background: rgba(167,139,250,0.1); color: #a78bfa; }
        .ni-default  { background: var(--glass); color: rgba(202,240,248,0.45); }

        .notif-body { flex: 1; min-width: 0; }
        .notif-msg  { font-size: 0.9rem; color: var(--foam); line-height: 1.55; word-break: break-word; }
        .notif-time { font-size: 0.72rem; color: rgba(202,240,248,0.32); margin-top: 5px; display: flex; align-items: center; gap: 5px; }

        /* ── EMPTY STATE ── */
        .empty-state { text-align: center; padding: 72px 20px; background: linear-gradient(145deg, rgba(10,45,74,0.4), rgba(3,15,30,0.6)); border: 1px solid var(--glass-border); border-radius: 18px; }
        .empty-ring  { width: 86px; height: 86px; border-radius: 50%; background: rgba(0,180,216,0.07); border: 1px solid rgba(0,180,216,0.12); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.8rem; color: rgba(0,180,216,0.2); }
        .empty-state h5 { font-family: 'Cormorant Garamond', serif; font-size: 1.4rem; font-weight: 400; color: var(--white); margin-bottom: 8px; }
        .empty-state p  { font-size: 0.86rem; color: rgba(202,240,248,0.35); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
        }

        @media (max-width: 576px) { .main-content { padding: 16px 14px; } }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <div>
            <div class="sidebar-logo-text">De Chavez Waterhaus</div>
            <div class="sidebar-logo-sub">Employee Portal</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="employee_dashboard.php" class="nav-link"><i class="fas fa-house"></i> Dashboard</a>
        <a href="attendance.php"         class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payslip.php"            class="nav-link"><i class="fas fa-file-invoice-dollar"></i> My Payslip</a>
        <a href="leave_request.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Leave Requests</a>
        <a href="my_deliveries.php"      class="nav-link"><i class="fas fa-truck"></i> My Deliveries</a>
        <a href="profile.php"            class="nav-link"><i class="fas fa-user"></i> My Profile</a>
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
            <div class="topbar-left">
                <h4>Notifications</h4>
                <p>Stay updated with your activities and shifts</p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($employee['profile_picture'])&&file_exists('../'.$employee['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($employee['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName);?></div>
                        <div class="avatar-role">Employee</div>
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

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex align-items-center gap-3">
            <div class="ph-icon"><i class="fas fa-bell"></i></div>
            <div>
                <div class="ph-title">Your Notifications</div>
                <div class="ph-sub">All notifications are marked as read when you open this page</div>
            </div>
        </div>
        <div class="count-pill">
            <i class="fas fa-layer-group" style="color:rgba(0,180,216,0.45);"></i>
            <strong><?php echo $totalCount;?></strong> total
        </div>
    </div>

    <?php if($totalCount > 0):
        // Re-fetch for rendering with type detection
        $notifs->data_seek(0);
        $allNotifs = $notifs->fetch_all(MYSQLI_ASSOC);
    ?>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <button class="filter-tab active" onclick="filterNotifs('all', this)">All</button>
        <button class="filter-tab" onclick="filterNotifs('clock', this)">Attendance</button>
        <button class="filter-tab" onclick="filterNotifs('truck', this)">Deliveries</button>
        <button class="filter-tab" onclick="filterNotifs('pay', this)">Payroll</button>
        <button class="filter-tab" onclick="filterNotifs('alert', this)">Alerts</button>
    </div>

    <!-- Notifications -->
    <div id="notifList">
        <?php
        $prevDate = null;
        $today    = date('Y-m-d');
        $yest     = date('Y-m-d', strtotime('-1 day'));

        foreach($allNotifs as $notif):
            $ts      = strtotime($notif['created_at']);
            $msgDate = date('Y-m-d', $ts);
            $msgLow  = strtolower($notif['message']);

            // Determine type
            if (str_contains($msgLow, 'clock') || str_contains($msgLow, 'attendance') || str_contains($msgLow, 'shift')) {
                $type = 'clock'; $icon = 'fa-clock'; $iconClass = 'ni-clock';
            } elseif (str_contains($msgLow, 'deliver') || str_contains($msgLow, 'order') || str_contains($msgLow, 'truck')) {
                $type = 'truck'; $icon = 'fa-truck'; $iconClass = 'ni-truck';
            } elseif (str_contains($msgLow, 'pay') || str_contains($msgLow, 'salary') || str_contains($msgLow, 'payslip') || str_contains($msgLow, 'earning')) {
                $type = 'pay';   $icon = 'fa-peso-sign'; $iconClass = 'ni-pay';
            } elseif (str_contains($msgLow, 'leave') || str_contains($msgLow, 'absent') || str_contains($msgLow, 'late') || str_contains($msgLow, 'warning')) {
                $type = 'alert'; $icon = 'fa-triangle-exclamation'; $iconClass = 'ni-alert';
            } else {
                $type = 'all'; $icon = 'fa-bell'; $iconClass = 'ni-default';
            }

            // Date group label
            if($msgDate !== $prevDate):
                $labelText = match(true) {
                    $msgDate === $today => 'Today',
                    $msgDate === $yest  => 'Yesterday',
                    default             => date('F j, Y', $ts)
                };
        ?>
        <div class="date-divider"><?php echo $labelText;?></div>
        <?php
            $prevDate = $msgDate;
            endif;
        ?>
        <div class="notif-item" data-type="<?php echo $type;?>">
            <div class="notif-icon <?php echo $iconClass;?>">
                <i class="fas <?php echo $icon;?>"></i>
            </div>
            <div class="notif-body">
                <div class="notif-msg"><?php echo htmlspecialchars($notif['message']);?></div>
                <div class="notif-time">
                    <i class="fas fa-clock"></i>
                    <?php echo date('g:i A', $ts);?>
                    <?php if($msgDate !== $today && $msgDate !== $yest): ?>
                        · <?php echo date('M j', $ts);?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- No results after filter -->
    <div id="noResults" style="display:none;text-align:center;padding:48px 20px;color:rgba(202,240,248,0.3);font-size:0.86rem;">
        <i class="fas fa-filter" style="font-size:2rem;display:block;margin-bottom:12px;color:rgba(0,180,216,0.15);"></i>
        No notifications match this filter.
    </div>

    <?php else: ?>

    <!-- Empty State -->
    <div class="empty-state">
        <div class="empty-ring"><i class="fas fa-bell-slash"></i></div>
        <h5>You're All Caught Up!</h5>
        <p>No notifications yet. We'll let you know when something happens.</p>
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
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth < 992) closeSidebar(); }));

    // ── FILTER ──
    function filterNotifs(type, btn) {
        document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const items    = document.querySelectorAll('.notif-item');
        let   visible  = 0;

        items.forEach(item => {
            const show = type === 'all' || item.dataset.type === type;
            item.style.display = show ? 'flex' : 'none';
            if(show) visible++;
        });

        // Show/hide date dividers
        document.querySelectorAll('.date-divider').forEach(div => {
            let next = div.nextElementSibling;
            let hasVisible = false;
            while(next && !next.classList.contains('date-divider')) {
                if(next.classList.contains('notif-item') && next.style.display !== 'none') {
                    hasVisible = true; break;
                }
                next = next.nextElementSibling;
            }
            div.style.display = hasVisible ? '' : 'none';
        });

        const noRes = document.getElementById('noResults');
        if(noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    }
</script>
</body>
</html>