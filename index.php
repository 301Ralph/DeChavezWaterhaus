<?php
session_start();
include 'includes/connection.php';

// Fetch available products
$productsQuery = "SELECT * FROM product";
$productsResult = $conn->query($productsQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>De Chavez Waterhaus • Pure Water Delivered</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="stylesheet" href="designs/index-style.css">
    <link rel="icon" href="images/logo.jpg" type="image/x-icon">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="images/logo.jpg" 
                    alt="De Chavez Waterhaus Logo" 
                    style="width: 55px; height: 55px; border-radius: 50%; object-fit: cover; margin-right: 12px; border: 2px solid #fff;">
                <span class="fw-bold fs-4 text-white">De Chavez Waterhaus</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#products">Products</a></li>
                    <li class="nav-item"><a class="nav-link" href="#why-us">Why Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
                    
                    <li class="nav-item ms-lg-3">
                        <button class="btn btn-outline-light btn-sm position-relative" onclick="showCartModal()">
                            <i class="fas fa-shopping-cart fa-lg"></i>
                            <span id="cart-count" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-info">0</span>
                        </button>
                    </li>
                    
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-primary btn-sm px-3" href="login.php">
                            <i class="fas fa-user me-1"></i> Sign In
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-section d-flex align-items-center text-white position-relative">
        <div class="container text-center">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1 class="display-3 fw-bold mb-4">Pure Water.<br>Delivered Fresh.</h1>
                    <p class="lead fs-4 mb-5">Experience the cleanest, healthiest water in Cavite. Sourced with care, delivered with pride.</p>
                    <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
                        <button class="btn btn-light btn-lg px-5 py-3 fw-semibold shadow" 
                                onclick="window.location.href='<?php echo isset($_SESSION['userID']) ? ($_SESSION['role'] === 'admin' ? 'Admin/admin_dashboard.php' : 'Customer/order.php') : 'login.php'; ?>'">
                            <i class="fas fa-shopping-bag me-2"></i> Order Now
                        </button>
                        <a href="#products" class="btn btn-outline-light btn-lg px-5 py-3 fw-semibold">
                            <i class="fas fa-play me-2"></i> Explore Products
                        </a>
                    </div>
                    <div class="mt-5">
                        <small class="text-white-50">Trusted by 2,500+ families in Noveleta &amp; surrounding areas</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Scroll indicator -->
        <div class="scroll-indicator position-absolute bottom-0 start-50 translate-middle-x mb-4 d-none d-lg-block">
            <a href="#products" class="text-white text-decoration-none">
                <i class="fas fa-chevron-down fa-2x pulse"></i>
            </a>
        </div>
    </section>

    <!-- Wave Divider -->
    <div class="wave-divider">
        <svg viewBox="0 0 1200 120" preserveAspectRatio="none">
            <path d="M0,0V46.29c47.79,22.2,103.59,32.17,158,28,70.36-5.37,136.33-33.31,206.8-37.5C438.64,32.43,512.34,53.67,583,72.05c69.27,18,138.3,24.88,209.4,13.08,36.15-6,69.85-17.84,104.45-29.34C989.49,25,1113-14.29,1200,2.47V0Z" fill="#ffffff"></path>
        </svg>
    </div>

    <!-- Products Section -->
    <section id="products" class="py-5 bg-white">
        <div class="container">
            <div class="text-center mb-5">
                <span class="badge bg-info text-dark px-3 py-2 mb-2">FRESH &amp; PURE</span>
                <h2 class="display-5 fw-bold">Our Premium Water Collection</h2>
                <p class="lead text-muted">Choose from our range of purified, distilled, mineral, and alkaline water in convenient 5-gallon containers.</p>
            </div>

            <!-- Search and Filter -->
            <div class="row mb-4">
                <div class="col-md-6 mx-auto">
                    <div class="input-group input-group-lg shadow-sm">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="productSearch" class="form-control border-start-0" placeholder="Search products (e.g. Purified, Alkaline)...">
                    </div>
                </div>
            </div>

            <div class="row g-4" id="products-grid">
                <?php
                $defaultImages = [
                    '1' => 'images/waterdispenser.jpg',
                    '2' => 'images/default-upload.jpg'
                ];
                $productIndex = 1;
                while ($product = $productsResult->fetch_assoc()) {
                    $imageURL = $product['ImageURL'];
                    $productID = $product['ProductID'];
                    if (!file_exists($imageURL) || empty($imageURL)) {
                        $imageURL = $defaultImages[$productIndex];
                    }
                ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card product-card h-100 border-0 shadow-sm" 
                             data-id="<?php echo $productID; ?>" 
                             data-name="<?php echo htmlspecialchars($product['ProductName']); ?>" 
                             data-price="<?php echo $product['Price']; ?>"
                             data-image="<?php echo $imageURL; ?>">
                            <div class="position-relative overflow-hidden">
                                <img src="<?php echo $imageURL; ?>" class="card-img-top" alt="<?php echo $product['ProductName']; ?>" 
                                     style="height: 220px; object-fit: cover;" 
                                     onerror="this.src='<?php echo $defaultImages[$productIndex]; ?>';">
                                <div class="position-absolute top-0 end-0 m-3">
                                    <span class="badge bg-white text-dark shadow-sm px-3 py-1">5 Gallon</span>
                                </div>
                            </div>
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title fw-semibold"><?php echo $product['ProductName']; ?></h5>
                                <p class="card-text text-muted flex-grow-1"><?php echo $product['Description']; ?></p>
                                
                                <div class="mt-auto">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <span class="fs-4 fw-bold text-primary">₱<?php echo number_format($product['Price'], 2); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-success"><i class="fas fa-check-circle"></i> In Stock</small>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2 d-sm-flex">
                                        <button class="btn btn-outline-primary flex-sm-fill" onclick="viewProduct(this)">
                                            <i class="fas fa-eye me-1"></i> Details
                                        </button>
                                        <button class="btn btn-primary flex-sm-fill" onclick="addToCartFromCard(this)">
                                            <i class="fas fa-cart-plus me-1"></i> Add to Cart
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
                    $productIndex = ($productIndex % 2) + 1;
                } ?>
            </div>
            
            <div class="text-center mt-5">
                <p class="text-muted">Need bulk orders or water dispenser rental? <a href="#faq" class="text-primary fw-semibold">Contact us</a></p>
            </div>
        </div>
    </section>

    <!-- Why Choose Us -->
    <section id="why-us" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">Why Families Choose De Chavez Waterhaus</h2>
                <p class="lead text-muted">More than just water — it's peace of mind in every drop.</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card border-0 h-100 text-center p-4 shadow-sm">
                        <div class="icon-wrapper mx-auto mb-4 bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                            <i class="fas fa-shield-alt fa-3x"></i>
                        </div>
                        <h5 class="fw-semibold">Multi-Stage Purification</h5>
                        <p class="text-muted">Advanced filtration, UV sterilization, and mineral balancing for the purest taste and health benefits.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 h-100 text-center p-4 shadow-sm">
                        <div class="icon-wrapper mx-auto mb-4 bg-info text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                            <i class="fas fa-truck fa-3x"></i>
                        </div>
                        <h5 class="fw-semibold">Same-Day Delivery</h5>
                        <p class="text-muted">Order before 2 PM and receive fresh water at your doorstep the same day across Noveleta and nearby areas.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 h-100 text-center p-4 shadow-sm">
                        <div class="icon-wrapper mx-auto mb-4 bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                            <i class="fas fa-recycle fa-3x"></i>
                        </div>
                        <h5 class="fw-semibold">Eco-Friendly Refills</h5>
                        <p class="text-muted">Reusable 5-gallon containers reduce plastic waste. We sanitize and refill — good for you and the planet.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="py-5 bg-white">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">Loved by Our Community</h2>
                <p class="lead text-muted">Real stories from real families in Cavite</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 p-4">
                        <div class="d-flex mb-3">
                            <img src="https://i.pravatar.cc/60?img=28" class="rounded-circle me-3" alt="Maria Santos">
                            <div>
                                <h6 class="mb-0 fw-semibold">Maria Santos</h6>
                                <small class="text-muted">Noveleta • 3 years customer</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                        </div>
                        <p class="fst-italic">"The water tastes so clean and fresh. My kids love it and I feel safe giving it to them every day. Delivery is always on time!"</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 p-4">
                        <div class="d-flex mb-3">
                            <img src="https://i.pravatar.cc/60?img=32" class="rounded-circle me-3" alt="Juan Dela Cruz">
                            <div>
                                <h6 class="mb-0 fw-semibold">Juan Dela Cruz</h6>
                                <small class="text-muted">Imus • 2 years customer</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star-half-alt text-warning"></i>
                        </div>
                        <p class="fst-italic">"Best decision for our office. The alkaline water gives us more energy. The team is professional and the dispenser rental is very affordable."</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 p-4">
                        <div class="d-flex mb-3">
                            <img src="https://i.pravatar.cc/60?img=47" class="rounded-circle me-3" alt="Ana Reyes">
                            <div>
                                <h6 class="mb-0 fw-semibold">Ana Reyes</h6>
                                <small class="text-muted">General Trias • 1 year customer</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                            <i class="fas fa-star text-warning"></i>
                        </div>
                        <p class="fst-italic">"I switched from another supplier and never looked back. The mineral water is perfect for my family’s daily hydration. Highly recommended!"</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Us -->
    <section id="about" class="py-5 bg-light">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <div class="pe-lg-5">
                        <span class="badge bg-primary px-3 py-2 mb-3">EST. 2015</span>
                        <h2 class="display-5 fw-bold mb-4">Your Trusted Partner for Clean Water in Cavite</h2>
                        <p class="lead">De Chavez Waterhaus is a family-owned business committed to delivering the highest quality drinking water to homes and offices across Noveleta, Imus, General Trias, and nearby communities.</p>
                        
                        <div class="mt-4">
                            <div class="d-flex align-items-center mb-3">
                                <i class="fas fa-map-marker-alt text-primary fa-2x me-3"></i>
                                <div>
                                    <strong>072 Nawasa, Sta. Rosa 1, Noveleta, Cavite</strong><br>
                                    <small class="text-muted">Near Tramo Road</small>
                                </div>
                            </div>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-phone text-primary fa-2x me-3"></i>
                                <div>
                                    <strong>Tel. No. 438-6311</strong><br>
                                    <small class="text-muted">Open Mon–Sat • 8:00 AM – 6:00 PM</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="position-relative">
                        <img src="https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&amp;fit=crop&amp;w=800&amp;q=80" 
                             class="img-fluid rounded-4 shadow-lg" alt="De Chavez Waterhaus water purification facility" style="max-height: 420px; object-fit: cover;">
                        <div class="position-absolute bottom-0 start-0 bg-white p-3 rounded-3 shadow m-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-award text-warning fa-2x me-2"></i>
                                <div>
                                    <small class="fw-bold">5-Star Rated</small><br>
                                    <small>by 2,500+ happy customers</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section id="faq" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">Frequently Asked Questions</h2>
                <p class="lead text-muted">Everything you need to know about our water delivery service</p>
            </div>
            
            <div class="accordion shadow-sm" id="faqAccordion" style="max-width: 800px; margin: 0 auto;">
                <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            What types of water do you offer?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            We proudly offer four premium options: <strong>Purified Water</strong>, <strong>Distilled Water</strong>, <strong>Mineral Water</strong>, and <strong>Alkaline Water</strong> — all in hygienic 5-gallon containers.
                        </div>
                    </div>
                </div>
                
                <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            How can I place an order?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Simply click the <strong>"Order Now"</strong> button, sign in or create an account, and select your preferred water type and quantity. You can also call us directly at 438-6311.
                        </div>
                    </div>
                </div>
                
                <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            What are your operating and delivery hours?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            We are open <strong>Monday to Saturday, 8:00 AM – 6:00 PM</strong>. Same-day delivery is available for orders placed before 2:00 PM within our service area.
                        </div>
                    </div>
                </div>
                
                <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            Do you offer water dispenser rentals?
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Yes! We offer affordable monthly rental plans for hot &amp; cold water dispensers. Perfect for homes and offices. Ask us for current rates when you place your first order.
                        </div>
                    </div>
                </div>
                
                <div class="accordion-item border-0 rounded-3 overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                            How do I contact customer service?
                        </button>
                    </h2>
                    <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Call us at <strong>438-6311</strong> or visit our station at 072 Nawasa, Sta. Rosa 1, Noveleta, Cavite. We also respond quickly to messages on our Facebook page.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-white pt-5 pb-4">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="d-flex align-items-center mb-3">
                        <i class="fas fa-tint fa-2x text-info me-2"></i>
                        <span class="fs-4 fw-bold">De Chavez Waterhaus</span>
                    </div>
                    <p class="text-white-50">Bringing pure, safe, and refreshing water to every home and workplace in Cavite since 2015.</p>
                    <div class="d-flex gap-3 mt-3">
                        <a href="#" class="text-white-50"><i class="fab fa-facebook-f fa-lg"></i></a>
                        <a href="#" class="text-white-50"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-white-50"><i class="fab fa-tiktok fa-lg"></i></a>
                    </div>
                </div>
                
                <div class="col-6 col-lg-2">
                    <h6 class="fw-semibold mb-3">Quick Links</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#home" class="text-white-50 text-decoration-none">Home</a></li>
                        <li class="mb-2"><a href="#products" class="text-white-50 text-decoration-none">Products</a></li>
                        <li class="mb-2"><a href="#why-us" class="text-white-50 text-decoration-none">Why Us</a></li>
                        <li class="mb-2"><a href="#faq" class="text-white-50 text-decoration-none">FAQ</a></li>
                    </ul>
                </div>
                
                <div class="col-6 col-lg-3">
                    <h6 class="fw-semibold mb-3">Contact Us</h6>
                    <ul class="list-unstyled text-white-50">
                        <li class="mb-2"><i class="fas fa-phone me-2"></i> 438-6311</li>
                        <li class="mb-2"><i class="fas fa-map-marker-alt me-2"></i> 072 Nawasa, Sta. Rosa 1<br>Noveleta, Cavite</li>
                        <li><i class="fas fa-clock me-2"></i> Mon–Sat: 8AM – 6PM</li>
                    </ul>
                </div>
                
                <div class="col-lg-3">
                    <div class="card bg-primary border-0 text-white p-4">
                        <h6 class="fw-bold">Ready to hydrate smarter?</h6>
                        <p class="small mb-3">Join thousands of happy customers today.</p>
                        <button class="btn btn-light btn-sm fw-semibold" onclick="window.location.href='<?php echo isset($_SESSION['userID']) ? ($_SESSION['role'] === 'admin' ? 'Admin/admin_dashboard.php' : 'Customer/order.php') : 'login.php'; ?>'">
                            Start Your Order
                        </button>
                    </div>
                </div>
            </div>
            
            <hr class="my-4 border-secondary">
            
            <div class="text-center text-white-50 small">
                © 2026 De Chavez Waterhaus. All rights reserved. | Designed with ❤️ for Cavite families
            </div>
        </div>
    </footer>

    <!-- Product Detail Modal -->
    <div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <img id="modalImage" class="img-fluid rounded-4 shadow-sm w-100" style="max-height: 380px; object-fit: cover;" alt="">
                        </div>
                        <div class="col-md-6">
                            <div id="modalBadge" class="badge bg-info text-dark mb-2">5 Gallon Container</div>
                            <h3 id="modalName" class="fw-bold mb-2"></h3>
                            <div class="d-flex align-items-baseline mb-3">
                                <span id="modalPrice" class="fs-2 fw-bold text-primary"></span>
                            </div>
                            
                            <div class="mb-4">
                                <h6 class="fw-semibold text-muted mb-2">Description</h6>
                                <p id="modalDesc" class="text-muted"></p>
                            </div>
                            
                            <div class="mb-4">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>Availability</span>
                                    <span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i> In Stock • Ready to Deliver</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-success" style="width: 95%"></div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button id="modalAddToCartBtn" class="btn btn-primary btn-lg" onclick="addCurrentProductToCart()">
                                    <i class="fas fa-cart-plus me-2"></i> Add to Cart
                                </button>
                            </div>
                            
                            <div class="text-center mt-3">
                                <small class="text-muted">Free delivery on orders above ₱500</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Modal -->
    <div class="modal fade" id="cartModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-shopping-cart me-2"></i> Your Cart</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="cartItems" class="p-4" style="max-height: 420px; overflow-y: auto;">
                        <!-- Cart items populated by JS -->
                    </div>
                    
                    <div class="p-4 bg-light border-top" id="cartFooter">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-semibold fs-5">Total</span>
                            <span id="cartTotal" class="fw-bold fs-4 text-primary">₱0.00</span>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-success btn-lg fw-semibold" onclick="checkout()">
                                <i class="fas fa-check-circle me-2"></i> Proceed to Checkout
                            </button>
                            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Continue Shopping</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
        <div id="liveToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body fw-semibold" id="toastMessage"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cart functionality
        let cart = [];
        
        // Load cart from localStorage
        function loadCart() {
            const savedCart = localStorage.getItem('waterhausCart');
            if (savedCart) {
                cart = JSON.parse(savedCart);
                updateCartCount();
            }
        }
        
        // Save cart to localStorage
        function saveCart() {
            localStorage.setItem('waterhausCart', JSON.stringify(cart));
            updateCartCount();
        }
        
        function updateCartCount() {
            const count = cart.reduce((sum, item) => sum + item.qty, 0);
            const badge = document.getElementById('cart-count');
            if (badge) badge.textContent = count;
        }
        
        function showToast(message, type = 'success') {
            const toastEl = document.getElementById('liveToast');
            const toastBody = document.getElementById('toastMessage');
            
            toastBody.textContent = message;
            toastEl.className = `toast align-items-center text-white bg-${type} border-0`;
            
            const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
            toast.show();
        }
        
        function addToCart(product) {
            const existing = cart.findIndex(item => item.id == product.id);
            
            if (existing !== -1) {
                cart[existing].qty++;
            } else {
                cart.push({ ...product, qty: 1 });
            }
            
            saveCart();
            showToast(`${product.name} added to cart!`, 'success');
            
            // Optional: open cart automatically after first add (commented for better UX)
            // if (cart.length === 1) setTimeout(showCartModal, 800);
        }
        
        function addToCartFromCard(btn) {
            const card = btn.closest('.product-card');
            const product = {
                id: card.dataset.id,
                name: card.dataset.name,
                price: parseFloat(card.dataset.price),
                image: card.dataset.image || card.querySelector('img').src
            };
            addToCart(product);
        }
        
        let currentProduct = null;
        
        function viewProduct(btn) {
            const card = btn.closest('.product-card');
            
            currentProduct = {
                id: card.dataset.id,
                name: card.dataset.name,
                price: parseFloat(card.dataset.price),
                image: card.dataset.image || card.querySelector('img').src,
                desc: card.querySelector('.card-text').textContent
            };
            
            // Populate modal
            document.getElementById('modalImage').src = currentProduct.image;
            document.getElementById('modalName').textContent = currentProduct.name;
            document.getElementById('modalPrice').textContent = `₱${currentProduct.price.toFixed(2)}`;
            document.getElementById('modalDesc').textContent = currentProduct.desc;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('productModal'));
            modal.show();
        }
        
        function addCurrentProductToCart() {
            if (currentProduct) {
                addToCart(currentProduct);
                
                // Close modal after adding
                const modalEl = document.getElementById('productModal');
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();
            }
        }
        
        function changeCartQty(index, delta) {
            cart[index].qty = Math.max(1, cart[index].qty + delta);
            renderCartItems();
            saveCart();
        }
        
        function removeFromCart(index) {
            const removed = cart[index];
            cart.splice(index, 1);
            renderCartItems();
            saveCart();
            showToast(`${removed.name} removed from cart`, 'danger');
        }
        
        function renderCartItems() {
            const container = document.getElementById('cartItems');
            const totalEl = document.getElementById('cartTotal');
            
            if (!container || !totalEl) return;
            
            if (cart.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-shopping-cart fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">Your cart is empty</h5>
                        <p class="text-muted small">Start adding some refreshing water!</p>
                    </div>
                `;
                totalEl.textContent = '₱0.00';
                return;
            }
            
            let html = '';
            let total = 0;
            
            cart.forEach((item, index) => {
                const itemTotal = item.price * item.qty;
                total += itemTotal;
                
                html += `
                    <div class="d-flex align-items-center mb-4 pb-3 border-bottom">
                        <img src="${item.image}" alt="${item.name}" class="rounded-3 shadow-sm" style="width: 70px; height: 70px; object-fit: cover;">
                        
                        <div class="flex-grow-1 ms-3">
                            <h6 class="fw-semibold mb-1">${item.name}</h6>
                            <div class="text-muted small">₱${item.price.toFixed(2)} each</div>
                            
                            <div class="d-flex align-items-center mt-2">
                                <button class="btn btn-sm btn-outline-secondary px-2 py-0" onclick="changeCartQty(${index}, -1)">−</button>
                                <span class="mx-3 fw-semibold">${item.qty}</span>
                                <button class="btn btn-sm btn-outline-secondary px-2 py-0" onclick="changeCartQty(${index}, 1)">+</button>
                            </div>
                        </div>
                        
                        <div class="text-end" style="min-width: 90px;">
                            <div class="fw-bold">₱${itemTotal.toFixed(2)}</div>
                            <button class="btn btn-sm text-danger p-0 mt-1" onclick="removeFromCart(${index})">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            totalEl.textContent = `₱${total.toFixed(2)}`;
        }
        
        function showCartModal() {
            const modal = new bootstrap.Modal(document.getElementById('cartModal'));
            renderCartItems();
            modal.show();
        }
        
        function checkout() {
            if (cart.length === 0) return;
            
            const total = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
            
            // Close cart modal
            const cartModalEl = document.getElementById('cartModal');
            const cartModal = bootstrap.Modal.getInstance(cartModalEl);
            if (cartModal) cartModal.hide();
            
            // Simulate order processing
            setTimeout(() => {
                showToast(`🎉 Order placed successfully! Total: ₱${total.toFixed(2)}. We'll contact you shortly for delivery.`, 'success');
                
                // Clear cart
                cart = [];
                saveCart();
                
                // Optional: redirect to order page if logged in
                // window.location.href = 'Customer/order.php';
            }, 600);
        }
        
        // Product search filter
        function setupProductSearch() {
            const searchInput = document.getElementById('productSearch');
            if (!searchInput) return;
            
            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase().trim();
                const cards = document.querySelectorAll('#products-grid .col-md-6, #products-grid .col-lg-4');
                
                cards.forEach(card => {
                    const title = card.querySelector('.card-title').textContent.toLowerCase();
                    const desc = card.querySelector('.card-text').textContent.toLowerCase();
                    
                    if (title.includes(term) || desc.includes(term)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
            
            // Clear on escape
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    document.querySelectorAll('#products-grid .col-md-6, #products-grid .col-lg-4').forEach(c => c.style.display = '');
                }
            });
        }
        
        // Initialize everything
        function initializeWebsite() {
            loadCart();
            updateCartCount();
            setupProductSearch();
            
            // Keyboard shortcut for cart (press 'c')
            document.addEventListener('keydown', function(e) {
                if (e.key.toLowerCase() === 'c' && document.activeElement.tagName === 'BODY') {
                    e.preventDefault();
                    showCartModal();
                }
            });
            
            // Add subtle animation to hero on load
            const hero = document.querySelector('.hero-section');
            if (hero) {
                hero.style.opacity = '0';
                hero.style.transition = 'opacity 0.8s ease';
                setTimeout(() => {
                    hero.style.opacity = '1';
                }, 100);
            }
            
            // Show welcome toast on first visit (demo only)
            if (!localStorage.getItem('waterhausVisited')) {
                setTimeout(() => {
                    // showToast('Welcome to De Chavez Waterhaus! Pure water, delivered fresh.', 'info');
                    localStorage.setItem('waterhausVisited', 'true');
                }, 4500);
            }
            
            console.log('%c[De Chavez Waterhaus] Redesigned website initialized successfully.', 'color:#00B4D8');
        }
        
        // Boot the app
        window.onload = initializeWebsite;
        
        // Bonus: Make sure modals are properly cleaned up
        document.addEventListener('hidden.bs.modal', function (event) {
            if (event.target.id === 'productModal') {
                currentProduct = null;
            }
        });
    </script>
</body>
</html>