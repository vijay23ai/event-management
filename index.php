<?php
$page_title = "Welcome to Eventify";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$contact_success = '';
$contact_error = '';

// Handle Contact Us submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (!empty($name) && !empty($email) && !empty($subject) && !empty($message)) {
        try {
            // Self-healing table check
            $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB");

            $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $subject, $message]);
            $contact_success = "Your message has been sent successfully! We will get back to you soon.";
        } catch (PDOException $e) {
            $contact_error = "Failed to send message: " . $e->getMessage();
        }
    } else {
        $contact_error = "Please fill in all the fields.";
    }
}

// Fetch 6 upcoming events for the preview section
try {
    $up_stmt = $pdo->query("SELECT e.*, 
                                   u.name as organizer_name,
                                   MIN(tt.price) as min_price, 
                                   SUM(tt.remaining_seats) as seats_left,
                                   (SELECT AVG(rating) FROM reviews WHERE event_id = e.id) as avg_rating,
                                   (SELECT COUNT(id) FROM reviews WHERE event_id = e.id) as count_reviews
                            FROM events e 
                            LEFT JOIN tickets_types tt ON e.id = tt.event_id
                            LEFT JOIN users u ON e.organizer_id = u.id
                            WHERE e.status = 'approved' AND e.date_time >= NOW() 
                            GROUP BY e.id 
                            ORDER BY e.date_time ASC 
                            LIMIT 6");
    $upcoming_events = $up_stmt->fetchAll();

    // Fetch active organizers
    $org_stmt = $pdo->query("SELECT u.id, u.name, COUNT(e.id) as total_events 
                             FROM users u 
                             JOIN events e ON u.id = e.organizer_id 
                             WHERE u.role = 'organizer' AND e.status = 'approved'
                             GROUP BY u.id 
                             ORDER BY total_events DESC 
                             LIMIT 4");
    $featured_organizers = $org_stmt->fetchAll();
} catch (PDOException $e) {
    $upcoming_events = [];
    $featured_organizers = [];
}

function render_event_card($event) {
    $min_price = $event['min_price'] ?? 0;
    $avg_rating = round($event['avg_rating'] ?? 0, 1);
    $count_reviews = $event['count_reviews'] ?? 0;
    $is_sold_out = ($event['remaining_seats'] ?? $event['seats_left'] ?? 0) <= 0;
    $is_upcoming = (strtotime($event['date_time']) > time());
    $organizer_name = $event['organizer_name'] ?? 'Organizer';
    $org_initial = strtoupper(substr($organizer_name, 0, 1));

    $cat_icons = [
        'concert' => 'bi-music-note-beamed', 'workshop' => 'bi-tools',
        'sports' => 'bi-trophy-fill', 'seminar' => 'bi-journal-code',
        'festival' => 'bi-emoji-laughing-fill', 'meetup' => 'bi-people-fill',
        'exhibition' => 'bi-image', 'cultural event' => 'bi-palette',
        'corporate event' => 'bi-briefcase'
    ];
    $cat_key = strtolower($event['category']);
    $icon = $cat_icons[$cat_key] ?? 'bi-tag-fill';
    $badge_class = in_array($cat_key, ['sports', 'festival']) ? 'badge-sky' : 'badge-indigo';
    $formatted_date = date('M d, Y · h:i A', strtotime($event['date_time']));
    $formatted_price = ($min_price == 0) ? 'Free' : '₹' . number_format($min_price, 0);
    $banner = !empty($event['banner_image']) ? htmlspecialchars($event['banner_image']) : 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&q=80&w=800';
    ?>
    <div class="col-md-6 col-lg-4 mb-4 d-flex">
        <div class="event-card-v2 w-100">
            <div class="card-image-wrap">
                <img src="<?php echo $banner; ?>" alt="<?php echo htmlspecialchars($event['title']); ?>" loading="lazy">
                <div class="card-img-overlay-gradient"></div>
                <div class="card-badge-corner">
                    <span class="badge badge-custom <?php echo $badge_class; ?>">
                        <i class="bi <?php echo $icon; ?> me-1"></i><?php echo htmlspecialchars($event['category']); ?>
                    </span>
                </div>
                <div class="card-rating-corner">
                    <?php if ($avg_rating > 0): ?>
                        <span class="badge" style="background:rgba(0,0,0,0.65);color:#fbbf24;font-weight:700;">
                            <i class="bi bi-star-fill me-1"></i><?php echo $avg_rating; ?>
                            <span style="color:rgba(255,255,255,0.7);font-size:0.7rem;">(<?php echo $count_reviews; ?>)</span>
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background:rgba(0,0,0,0.55);color:rgba(255,255,255,0.75);">New</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body-inner">
                <div class="organizer-row">
                    <div class="organizer-avatar"><?php echo $org_initial; ?></div>
                    <span class="organizer-name"><?php echo htmlspecialchars($organizer_name); ?></span>
                    <?php if ($is_sold_out): ?>
                        <span class="badge bg-danger ms-auto" style="font-size:0.68rem;">Sold Out</span>
                    <?php elseif (!$is_upcoming): ?>
                        <span class="badge bg-secondary ms-auto" style="font-size:0.68rem;">Past</span>
                    <?php else: ?>
                        <span class="badge ms-auto" style="background:rgba(16,185,129,0.12);color:#10b981;border:1px solid rgba(16,185,129,0.25);font-size:0.68rem;"><?php echo $event['remaining_seats'] ?? $event['seats_left'] ?? 0; ?> left</span>
                    <?php endif; ?>
                </div>
                <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                <div class="event-meta-row"><i class="bi bi-calendar3"></i><span><?php echo $formatted_date; ?></span></div>
                <div class="event-meta-row"><i class="bi bi-geo-alt-fill"></i><span><?php echo htmlspecialchars($event['venue'] ?? $event['city']); ?>, <?php echo htmlspecialchars($event['city']); ?></span></div>
                <div class="card-footer-bar">
                    <div>
                        <div class="event-price-label">Starting from</div>
                        <div class="event-price"><?php echo $formatted_price; ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="/event.php?id=<?php echo $event['id']; ?>" class="btn btn-glass btn-sm rounded-pill px-3">Details</a>
                        <?php if ($is_upcoming && !$is_sold_out && $event['status'] === 'approved'): ?>
                            <a href="/book.php?event_id=<?php echo $event['id']; ?>" class="btn btn-primary-gradient btn-sm rounded-pill px-3">Book</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Custom Premium Styles for Landing Page -->
<style>
    :root {
        --landing-gradient-1: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        --landing-glow-indigo: rgba(79, 70, 229, 0.05);
        --landing-glow-purple: rgba(124, 58, 237, 0.05);
    }
    
    body {
        background-color: var(--bg-primary) !important;
        color: var(--text-primary) !important;
        overflow-x: hidden;
    }
    
    /* Hero section styling */
    .hero-wrapper {
        min-height: 95vh;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.05) 0%, transparent 40%),
                    radial-gradient(circle at 90% 80%, rgba(124, 58, 237, 0.05) 0%, transparent 40%),
                    var(--bg-secondary);
        padding: 100px 0;
        overflow: hidden;
    }

    .hero-glow-1 {
        position: absolute;
        width: 300px;
        height: 300px;
        background: #4f46e5;
        filter: blur(150px);
        opacity: 0.08;
        top: 10%;
        left: 5%;
        border-radius: 50%;
        z-index: 0;
    }

    .hero-glow-2 {
        position: absolute;
        width: 400px;
        height: 400px;
        background: #7c3aed;
        filter: blur(180px);
        opacity: 0.06;
        bottom: 15%;
        right: 5%;
        border-radius: 50%;
        z-index: 0;
    }

    /* Animated background grid */
    .hero-grid {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-image: linear-gradient(rgba(0, 0, 0, 0.015) 1px, transparent 1px),
                          linear-gradient(90deg, rgba(0, 0, 0, 0.015) 1px, transparent 1px);
        background-size: 40px 40px;
        z-index: 1;
    }

    .hero-content {
        position: relative;
        z-index: 2;
    }

    .badge-premium {
        background: rgba(99, 102, 241, 0.06);
        border: 1px solid rgba(99, 102, 241, 0.15);
        color: #4f46e5;
        padding: 8px 16px;
        border-radius: 50px;
        font-weight: 500;
        font-size: 0.85rem;
        letter-spacing: 1px;
        text-transform: uppercase;
        display: inline-block;
        margin-bottom: 20px;
        backdrop-filter: blur(5px);
    }

    .hero-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 800;
        background: linear-gradient(135deg, #0f172a 30%, #312e81 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        font-size: 4rem;
        line-height: 1.15;
    }

    .hero-accent {
        background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .glass-card-premium {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(0, 0, 0, 0.06);
        border-radius: 20px;
        transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
        box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.06);
    }

    .glass-card-premium:hover {
        transform: translateY(-8px);
        border-color: rgba(99, 102, 241, 0.2);
        box-shadow: 0 20px 40px -15px rgba(99, 102, 241, 0.1);
    }

    .icon-box-premium {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.06) 0%, rgba(124, 58, 237, 0.06) 100%);
        border: 1px solid rgba(99, 102, 241, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: #4f46e5;
        margin-bottom: 24px;
        transition: all 0.3s ease;
    }

    .glass-card-premium:hover .icon-box-premium {
        background: var(--landing-gradient-1);
        color: white;
        border-color: transparent;
        box-shadow: 0 8px 20px rgba(124, 58, 237, 0.2);
    }

    .section-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        color: #0f172a;
        font-size: 2.5rem;
        margin-bottom: 15px;
    }

    .section-subtitle {
        color: #475569;
        max-width: 600px;
        margin: 0 auto 50px auto;
        font-size: 1.05rem;
    }

    /* Category circle cards */
    .category-card {
        text-align: center;
        padding: 30px 20px;
        border-radius: 24px;
        background: rgba(255, 255, 255, 0.8);
        border: 1px solid rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
        cursor: pointer;
        display: block;
        text-decoration: none;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.01);
    }

    .category-card:hover {
        background: rgba(99, 102, 241, 0.04);
        border-color: rgba(99, 102, 241, 0.2);
        transform: scale(1.03);
        box-shadow: 0 8px 25px rgba(99, 102, 241, 0.06);
    }

    .category-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
        background: var(--landing-gradient-1);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        display: inline-block;
    }

    /* Counter block styling */
    .counter-section {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(99, 102, 241, 0.03) 50%, rgba(255, 255, 255, 0) 100%);
        padding: 80px 0;
    }

    .counter-number {
        font-family: 'Poppins', sans-serif;
        font-weight: 800;
        font-size: 3.5rem;
        background: linear-gradient(135deg, #0f172a 0%, #4f46e5 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        line-height: 1;
        margin-bottom: 10px;
    }

    /* Gallery Grid Masonry */
    .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        grid-gap: 20px;
    }

    .gallery-item {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
        height: 240px;
        cursor: pointer;
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .gallery-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.6s cubic-bezier(0.165, 0.84, 0.44, 1);
    }

    .gallery-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(to top, rgba(15, 23, 42, 0.9) 0%, rgba(15, 23, 42, 0.2) 100%);
        opacity: 0;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        padding: 20px;
        transition: opacity 0.3s ease;
    }

    .gallery-item:hover .gallery-img {
        transform: scale(1.1);
    }

    .gallery-item:hover .gallery-overlay {
        opacity: 1;
    }

    /* Accordion Custom styling */
    .accordion-button-glass {
        background: rgba(255, 255, 255, 0.8) !important;
        color: #0f172a !important;
        border: 1px solid rgba(0, 0, 0, 0.05) !important;
        border-radius: 12px !important;
        box-shadow: none !important;
        transition: all 0.3s ease;
    }

    .accordion-button-glass:not(.collapsed) {
        border-color: rgba(99, 102, 241, 0.2) !important;
        background: rgba(99, 102, 241, 0.05) !important;
        color: #4f46e5 !important;
    }

    .accordion-item-glass {
        background: transparent !important;
        border: none !important;
        margin-bottom: 12px;
    }

    .accordion-body-glass {
        background: rgba(255, 255, 255, 0.4);
        border: 1px solid rgba(0, 0, 0, 0.03);
        border-top: none;
        border-radius: 0 0 12px 12px;
        color: #475569;
        padding: 20px;
    }

    /* Scrolling Partner Carousel */
    @keyframes scroll {
        0% { transform: translateX(0); }
        100% { transform: translateX(calc(-250px * 5)); }
    }

    .slider-partners {
        height: 100px;
        margin: auto;
        overflow: hidden;
        position: relative;
        width: 100%;
    }

    .slider-partners::before, .slider-partners::after {
        background: linear-gradient(to right, #f8fafc 0%, transparent 100%);
        content: "";
        height: 100px;
        position: absolute;
        width: 200px;
        z-index: 2;
    }

    .slider-partners::after {
        right: 0;
        top: 0;
        transform: rotate(180deg);
    }

    .slider-partners::before {
        left: 0;
        top: 0;
    }

    .slide-track {
        animation: scroll 40s linear infinite;
        display: flex;
        width: calc(250px * 10);
    }

    .slide-logo {
        height: 100px;
        width: 250px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .partner-logo {
        height: 40px;
        opacity: 0.6;
        transition: opacity 0.3s ease;
        filter: grayscale(1) contrast(1.2);
    }

    .partner-logo:hover {
        opacity: 0.9;
    }

    @media (max-width: 768px) {
        .hero-title {
            font-size: 2.5rem;
        }
    }
</style>

<!-- HERO SECTION -->
<section id="hero" class="hero-wrapper">
    <div class="hero-grid"></div>
    <div class="hero-glow-1"></div>
    <div class="hero-glow-2"></div>
    <div class="container hero-content">
        <div class="row align-items-center">
            <div class="col-lg-7 text-start mb-5 mb-lg-0" data-aos="fade-right">
                <span class="badge-premium"><i class="fa-solid fa-bolt me-1 text-indigo"></i> Next-Gen Event Platform</span>
                <h1 class="hero-title mb-4">Discover. Organize.<br><span class="hero-accent">Experience.</span></h1>
                <p class="lead text-secondary mb-5 fs-5" style="max-width: 600px; line-height: 1.6;">
                    The Ultimate City-Wide Event Management Platform for Attendees and Organizers. Book tickets, generate secure QR passes, and verify UPI transactions instantly.
                </p>
                
                <div class="d-flex flex-wrap gap-3">
                    <a href="discover.php" class="btn btn-primary-gradient py-3 px-5 rounded-pill shadow-lg" style="font-weight: 500;">
                        <i class="fa-solid fa-compass me-2"></i> Explore Events
                    </a>
                    <?php if (!$user): ?>
                        <a href="signup.php?role=organizer" class="btn btn-outline-light py-3 px-4 rounded-pill btn-glass" style="font-weight: 500;">
                            <i class="fa-solid fa-briefcase me-2"></i> Become an Organizer
                        </a>
                    <?php else: ?>
                        <a href="dashboard.php" class="btn btn-outline-light py-3 px-4 rounded-pill btn-glass" style="font-weight: 500;">
                            <i class="fa-solid fa-chart-line me-2"></i> Go to Dashboard
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-lg-5 text-center" data-aos="fade-left">
                <!-- Hero media: responsive video-like preview container -->
                <div class="position-relative mx-auto" style="max-width: 380px;">
                    <div class="rounded-5 overflow-hidden border border-light shadow-lg" style="aspect-ratio: 12/16; background: #000; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35); border-radius: 40px !important; position: relative;">
                        <!-- Phone notch -->
                        <div class="position-absolute top-0 start-50 translate-middle-x bg-dark rounded-bottom" style="width: 120px; height: 16px; z-index: 10;"></div>
                        <!-- ScreenApp iframe embed - title overlay hidden via container overflow -->
                        <div style="position: absolute; inset: 0; overflow: hidden; border-radius: 40px;">
                            <iframe class="w-100 h-100" src="https://embed.screenapp.io/app/v/_c4H0TaJf3?embed=true&autoplay=1&muted=1" frameborder="0" allowfullscreen allow="autoplay" style="border: 0; transform: scale(1.02); transform-origin: center;"></iframe>
                        </div>
                    </div>
                    <!-- Floating badge -->
                    <div class="position-absolute" style="bottom: -14px; left: 50%; transform: translateX(-50%); white-space: nowrap;">
                        <span style="background: rgba(79,70,229,0.9); color: white; padding: 6px 18px; border-radius: 50px; font-size: 0.78rem; font-weight: 600; backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.2);">
                            <i class="bi bi-play-circle-fill me-1"></i> Live Platform Demo
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ABOUT PLATFORM SECTION -->
<section id="about" class="py-5" style="position: relative;">
    <div class="container my-5">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">The Complete Event Loop</h2>
            <p class="section-subtitle">We build the bridges between passionate creators, organizers, and event goers.</p>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="glass-card-premium p-4 h-100">
                    <div class="icon-box-premium"><i class="fa-solid fa-search"></i></div>
                    <h5 class="text-dark fw-bold mb-3">Discover Locally</h5>
                    <p class="text-secondary small">Filter and find concerts, tech seminars, sports meets, and local workshops in seconds. Real-time availability checks.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="glass-card-premium p-4 h-100">
                    <div class="icon-box-premium"><i class="fa-solid fa-qrcode"></i></div>
                    <h5 class="text-dark fw-bold mb-3">Instant QR Tickets</h5>
                    <p class="text-secondary small">Your booking generates a unique secure check-in QR code immediately upon payment confirmation. Streamlined gate check-ins.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
                <div class="glass-card-premium p-4 h-100">
                    <div class="icon-box-premium"><i class="fa-solid fa-credit-card"></i></div>
                    <h5 class="text-dark fw-bold mb-3">Secure UPI Verification</h5>
                    <p class="text-secondary small">Submit offline payment receipts with transaction UTR numbers directly. Hosts verify proofs safely in dashboard portals.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- EVENT CATEGORIES SECTION -->
<section id="categories" class="py-5 bg-light" style="position: relative;">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">Explore by Category</h2>
            <p class="section-subtitle">Jump straight into the types of experiences you love the most.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php
            $cats = [
                ['name' => 'Concerts', 'icon' => 'fa-music', 'img' => 'https://images.unsplash.com/photo-1506157786151-b8491531f063?auto=format&fit=crop&q=80&w=400', 'slug' => 'Concert'],
                ['name' => 'Festivals', 'icon' => 'fa-masks-theater', 'img' => 'https://images.unsplash.com/photo-1467307983825-619ab40a85c6?auto=format&fit=crop&q=80&w=400', 'slug' => 'Festival'],
                ['name' => 'Workshops', 'icon' => 'fa-graduation-cap', 'img' => 'https://images.unsplash.com/photo-1531403009284-440f080d1e12?auto=format&fit=crop&q=80&w=400', 'slug' => 'Workshop'],
                ['name' => 'Sports', 'icon' => 'fa-basketball', 'img' => 'https://images.unsplash.com/photo-1546519638-68e109498ffc?auto=format&fit=crop&q=80&w=400', 'slug' => 'Sports'],
                ['name' => 'Seminars', 'icon' => 'fa-laptop-code', 'img' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?auto=format&fit=crop&q=80&w=400', 'slug' => 'Seminar'],
                ['name' => 'Meetups', 'icon' => 'fa-users', 'img' => 'https://images.unsplash.com/photo-1511795409834-ef04bbd61622?auto=format&fit=crop&q=80&w=400', 'slug' => 'Meetup'],
            ];
            foreach ($cats as $idx => $c):
            ?>
                <div class="col-6 col-md-4 col-lg-2" data-aos="zoom-in" data-aos-delay="<?php echo $idx * 50; ?>">
                    <a href="discover.php?category=<?php echo urlencode($c['slug']); ?>" class="category-card">
                        <i class="fa-solid <?php echo $c['icon']; ?> category-icon"></i>
                        <h6 class="text-dark fw-bold mb-0 mt-2"><?php echo $c['name']; ?></h6>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- UPCOMING EVENTS PREVIEW -->
<section id="events" class="py-5">
    <div class="container my-5">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-5" data-aos="fade-up">
            <div>
                <h2 class="section-title mb-1">Upcoming Events Preview</h2>
                <p class="text-secondary mb-0">Browse the latest events added to the platform.</p>
            </div>
            <a href="discover.php" class="btn btn-glass mt-3 mt-md-0 rounded-pill px-4"><i class="fa-solid fa-arrow-right me-2"></i>View All Events</a>
        </div>

        <div class="row g-4">
            <?php if (empty($upcoming_events)): ?>
                <div class="col-12 text-center py-5">
                    <i class="fa-regular fa-calendar-xmark fs-1 text-secondary mb-3"></i>
                    <h5 class="text-dark">No Upcoming Events Available</h5>
                    <p class="text-secondary small">Events organized in the system will appear here soon.</p>
                </div>
            <?php else: ?>
                <?php foreach ($upcoming_events as $idx => $ev): ?>
                    <?php render_event_card($ev); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- STATS COUNTER SECTION -->
<section class="counter-section">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-6 col-lg-3" data-aos="fade-up">
                <div class="counter-number" data-target="10000">10,000+</div>
                <div class="text-secondary small uppercase tracking-wider">Tickets Sold</div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                <div class="counter-number" data-target="500">500+</div>
                <div class="text-secondary small uppercase tracking-wider">Events Organized</div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                <div class="counter-number" data-target="50">50+</div>
                <div class="text-secondary small uppercase tracking-wider">Cities Covered</div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                <div class="counter-number" data-target="25000">25,000+</div>
                <div class="text-secondary small uppercase tracking-wider">Happy Attendees</div>
            </div>
        </div>
    </div>
</section>

<!-- PREVIOUS EVENTS SHOWCASE -->
<section id="gallery" class="py-5 bg-light">
    <div class="container my-5">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">Previous Event Gallery</h2>
            <p class="section-subtitle">Relive some of the most memorable snapshots captured at our historical productions.</p>
        </div>

        <div class="gallery-grid">
            <div class="gallery-item" data-aos="zoom-in" data-aos-delay="100">
                <img src="https://images.unsplash.com/photo-1470225620780-dba8ba36b745?auto=format&fit=crop&q=80&w=600" class="gallery-img" alt="EDM Concert">
                <div class="gallery-overlay">
                    <h6 class="text-white fw-bold mb-0">Electric Rhythm Concert</h6>
                    <span class="text-white-50 small">Concerts & Parties</span>
                </div>
            </div>
            <div class="gallery-item" data-aos="zoom-in" data-aos-delay="200">
                <img src="https://images.unsplash.com/photo-1540575467063-178a50c2df87?auto=format&fit=crop&q=80&w=600" class="gallery-img" alt="Tech Conference">
                <div class="gallery-overlay">
                    <h6 class="text-white fw-bold mb-0">Global Web Tech Meetup</h6>
                    <span class="text-white-50 small">Seminars</span>
                </div>
            </div>
            <div class="gallery-item" data-aos="zoom-in" data-aos-delay="300">
                <img src="https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&q=80&w=600" class="gallery-img" alt="Corporate Workshop">
                <div class="gallery-overlay">
                    <h6 class="text-white fw-bold mb-0">Interaction Design Sprint</h6>
                    <span class="text-white-50 small">Workshops</span>
                </div>
            </div>
            <div class="gallery-item" data-aos="zoom-in" data-aos-delay="400">
                <img src="https://images.unsplash.com/photo-1461896836934-ffe607ba8211?auto=format&fit=crop&q=80&w=600" class="gallery-img" alt="Marathon Event">
                <div class="gallery-overlay">
                    <h6 class="text-white fw-bold mb-0">City Sunset Run</h6>
                    <span class="text-white-50 small">Sports & Outdoors</span>
                </div>
            </div>
            <div class="gallery-item" data-aos="zoom-in" data-aos-delay="500">
                <img src="https://images.unsplash.com/photo-1533174072545-7a4b6ad7a6c3?auto=format&fit=crop&q=80&w=600" class="gallery-img" alt="Night Carnival">
                <div class="gallery-overlay">
                    <h6 class="text-white fw-bold mb-0">Summer Lantern Carnival</h6>
                    <span class="text-white-50 small">Festivals</span>
                </div>
            </div>
            <div class="gallery-item" data-aos="zoom-in" data-aos-delay="600">
                <img src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&q=80&w=600" class="gallery-img" alt="Co-working Hackathon">
                <div class="gallery-overlay">
                    <h6 class="text-white fw-bold mb-0">Startup Grind Hackathon</h6>
                    <span class="text-white-50 small">Meetups</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FEATURED ORGANIZERS -->
<section id="organizers" class="py-5">
    <div class="container my-5">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">Featured Organizers</h2>
            <p class="section-subtitle">Learn from verified hosts and production companies managing event pipelines.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php if (empty($featured_organizers)): ?>
                <div class="col-12 text-center py-4"><p class="text-secondary">No verified organizers listed yet.</p></div>
            <?php else: ?>
                <?php foreach ($featured_organizers as $org): ?>
                    <div class="col-md-6 col-lg-3" data-aos="zoom-in">
                        <div class="glass-card-premium p-4 text-center" style="border-color: rgba(0,0,0,0.06);">
                            <div class="d-inline-block position-relative mb-3">
                                <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--landing-gradient-1); display: flex; align-items: center; justify-content: center; font-size: 2rem;" class="text-white fw-bold mx-auto border border-light">
                                    <?php echo strtoupper(substr($org['name'], 0, 1)); ?>
                                </div>
                                <span class="position-absolute bottom-0 end-0 bg-indigo rounded-circle border border-light p-1" style="width: 22px; height: 22px; display: flex; align-items: center; justify-content: center;" title="Verified Creator">
                                    <i class="fa-solid fa-check text-white" style="font-size: 0.65rem;"></i>
                                </span>
                            </div>
                            <h6 class="text-dark fw-bold mb-1"><?php echo htmlspecialchars($org['name']); ?></h6>
                            <span class="badge bg-indigo text-white small px-3 py-1 rounded-pill mb-3">Verified Host</span>
                            <div class="pt-3 border-top border-light d-flex justify-content-around small text-secondary">
                                <div>
                                    <strong class="text-dark"><?php echo $org['total_events']; ?></strong> Events
                                </div>
                                <div>
                                    <strong class="text-dark">4.8 <i class="fa-solid fa-star text-warning"></i></strong> Rating
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- REVIEWS & TESTIMONIALS SECTION -->
<section id="reviews" class="py-5 bg-light">
    <div class="container my-5">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">What Our Attendees Say</h2>
            <p class="section-subtitle">Real feedback from users who booked passes and attended events through Eventify.</p>
        </div>

        <div class="swiper swiper-testimonials py-4" data-aos="fade-up">
            <div class="swiper-wrapper">
                <div class="swiper-slide">
                    <div class="glass-card-premium p-4 m-2" style="border-color: rgba(0,0,0,0.06);">
                        <div class="d-flex align-items-center mb-3">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: #4f46e5; display:flex; align-items:center; justify-content:center; font-weight:bold; color:white;">A</div>
                            <div class="ms-3 text-start">
                                <h6 class="text-dark fw-bold mb-0">Alice Smith</h6>
                                <span class="text-secondary small">San Francisco, CA</span>
                            </div>
                        </div>
                        <div class="text-warning mb-2 small"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
                        <p class="text-secondary small italic mb-0">"The checkout wizard made it incredibly easy to book ticket tiers. Uploading my UPI proof was swift and approved within 15 minutes. High-quality experience!"</p>
                    </div>
                </div>
                <div class="swiper-slide">
                    <div class="glass-card-premium p-4 m-2" style="border-color: rgba(0,0,0,0.06);">
                        <div class="d-flex align-items-center mb-3">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: #7c3aed; display:flex; align-items:center; justify-content:center; font-weight:bold; color:white;">B</div>
                            <div class="ms-3 text-start">
                                <h6 class="text-dark fw-bold mb-0">Bob Jones</h6>
                                <span class="text-secondary small">Chicago, IL</span>
                            </div>
                        </div>
                        <div class="text-warning mb-2 small"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
                        <p class="text-secondary small italic mb-0">"Gate entry was seamless. We just opened the dashboard on our phones, showed the ticket QR code to the usher, they scanned it and we were in! Professional platform."</p>
                    </div>
                </div>
                <div class="swiper-slide">
                    <div class="glass-card-premium p-4 m-2" style="border-color: rgba(0,0,0,0.06);">
                        <div class="d-flex align-items-center mb-3">
                            <div style="width: 48px; height: 48px; border-radius: 50%; background: #06b6d4; display:flex; align-items:center; justify-content:center; font-weight:bold; color:white;">H</div>
                            <div class="ms-3 text-start">
                                <h6 class="text-dark fw-bold mb-0">Hannah Patel</h6>
                                <span class="text-secondary small">Austin, TX</span>
                            </div>
                        </div>
                        <div class="text-warning mb-2 small"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i></div>
                        <p class="text-secondary small italic mb-0">"As an organizer, the verification dashboard is a lifesaver. I can scroll through pending proofs, look at UTRs, inspect transaction screenshots, and approve booking receipts in one click."</p>
                    </div>
                </div>
            </div>
            <!-- Swiper pagination -->
            <div class="swiper-pagination mt-4"></div>
        </div>
    </div>
</section>

<!-- WHY CHOOSE US -->
<section class="py-5">
    <div class="container my-5">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">Why Choose Us</h2>
            <p class="section-subtitle">Modern technology infrastructure optimized for community events and ticket management.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="glass-card-premium p-4 h-100" style="border-color: rgba(0,0,0,0.06);">
                    <i class="fa-solid fa-shield-halved text-indigo fs-2 mb-3"></i>
                    <h5 class="text-dark fw-bold mb-2">Secure UPI Ecosystem</h5>
                    <p class="text-secondary small">Direct-to-bank peer-to-peer organizer settlements. Reduced commissions and secure verification loop.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="glass-card-premium p-4 h-100" style="border-color: rgba(0,0,0,0.06);">
                    <i class="fa-solid fa-bolt text-indigo fs-2 mb-3"></i>
                    <h5 class="text-dark fw-bold mb-2">Instant Ticket Dispatch</h5>
                    <p class="text-secondary small">No waiting queues. Tickets containing valid check-in tokens are instantly unlocked once payments are approved.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="glass-card-premium p-4 h-100" style="border-color: rgba(0,0,0,0.06);">
                    <i class="fa-solid fa-headset text-indigo fs-2 mb-3"></i>
                    <h5 class="text-dark fw-bold mb-2">Dedicated Refund Support</h5>
                    <p class="text-secondary small">Cancel tickets directly from your dashboard and raise automated refund claims processed swiftly by operators.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- SPONSORS AND PARTNERS CAROUSEL -->
<section class="py-5 bg-light">
    <div class="container">
        <div class="slider-partners">
            <div class="slide-track">
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/2/2f/Google_2015_logo.svg" class="partner-logo" alt="Google Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/5/51/Facebook_f_logo_%282019%29.svg" class="partner-logo" alt="Facebook Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/a/a9/Amazon_logo.svg" class="partner-logo" alt="Amazon Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/0/08/Netflix_2015_logo.svg" class="partner-logo" alt="Netflix Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/b/b8/Lenovo_logo_2015.svg" class="partner-logo" alt="Lenovo Logo"></div>
                <!-- Duplicate for seamless scroll -->
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/2/2f/Google_2015_logo.svg" class="partner-logo" alt="Google Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/5/51/Facebook_f_logo_%282019%29.svg" class="partner-logo" alt="Facebook Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/a/a9/Amazon_logo.svg" class="partner-logo" alt="Amazon Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/0/08/Netflix_2015_logo.svg" class="partner-logo" alt="Netflix Logo"></div>
                <div class="slide-logo"><img src="https://upload.wikimedia.org/wikipedia/commons/b/b8/Lenovo_logo_2015.svg" class="partner-logo" alt="Lenovo Logo"></div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ SECTION -->
<section id="faq" class="py-5">
    <div class="container my-5">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-subtitle">Quick answers to common questions about ticket bookings and organizing options.</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <div class="accordion" id="accordionFAQ">
                    <div class="accordion-item accordion-item-glass">
                        <h2 class="accordion-header">
                            <button class="accordion-button accordion-button-glass" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true">
                                How to book tickets?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse show" data-bs-parent="#accordionFAQ">
                            <div class="accordion-body-glass">
                                Browse to 'Explore Events', select your favorite event, select the desired ticket tier and quantity, enter attendee details, pay to the organizer's QR code displayed in step 3, upload a transaction screenshot with the UTR number, and submit! Once approved, your tickets appear on your dashboard.
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item accordion-item-glass">
                        <h2 class="accordion-header">
                            <button class="accordion-button accordion-button-glass collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                How to become an organizer?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#accordionFAQ">
                            <div class="accordion-body-glass">
                                Head to the 'Sign Up' page and register your account as an 'Organizer'. Once registered, you will have access to create and manage events, set up your custom UPI payment options, and verify payments.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item accordion-item-glass">
                        <h2 class="accordion-header">
                            <button class="accordion-button accordion-button-glass collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                How do refunds work?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#accordionFAQ">
                            <div class="accordion-body-glass">
                                If you wish to cancel your booking, navigate to your attendee dashboard and click 'Cancel Booking'. You can fill out a refund request form. Administrators will review the request and issue refunds based on event-specific policies.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item accordion-item-glass">
                        <h2 class="accordion-header">
                            <button class="accordion-button accordion-button-glass collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                Which payment methods are supported?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#accordionFAQ">
                            <div class="accordion-body-glass">
                                We support direct organizer peer-to-peer payments via any UPI app (e.g. Google Pay, PhonePe, Paytm) by scanning the event qr code or sending to the listed UPI VPA id.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT US SECTION -->
<section id="contact" class="py-5 bg-light">
    <div class="container my-5">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">Get in Touch</h2>
            <p class="section-subtitle">Have queries, concerns, or feedback? Drop us a line and our support desk will reach back.</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <div class="glass-card-premium p-5" style="border-color: rgba(0,0,0,0.06);">
                    <?php if ($contact_success): ?>
                        <div class="alert alert-success border-0 text-white mb-4" style="background: rgba(16,185,129,0.25);">
                            <i class="fa-solid fa-circle-check me-2"></i> <?php echo $contact_success; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($contact_error): ?>
                        <div class="alert alert-danger border-0 text-white mb-4" style="background: rgba(225,29,72,0.25);">
                            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo $contact_error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="index.php#contact">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="contact-name" class="form-label-glass">Your Name</label>
                                <input type="text" name="name" id="contact-name" class="form-control form-control-glass py-3" placeholder="e.g. John Doe" required>
                            </div>
                            <div class="col-md-6">
                                <label for="contact-email" class="form-label-glass">Your Email</label>
                                <input type="email" name="email" id="contact-email" class="form-control form-control-glass py-3" placeholder="e.g. john@example.com" required>
                            </div>
                            <div class="col-12 mt-3">
                                <label for="contact-subject" class="form-label-glass">Subject</label>
                                <input type="text" name="subject" id="contact-subject" class="form-control form-control-glass py-3" placeholder="e.g. Refund query, Partner inquiries" required>
                            </div>
                            <div class="col-12 mt-3">
                                <label for="contact-message" class="form-label-glass">Message</label>
                                <textarea name="message" id="contact-message" class="form-control form-control-glass" rows="5" placeholder="How can we help you?" required></textarea>
                            </div>
                            <div class="col-12 mt-4 text-center">
                                <button type="submit" name="contact_submit" class="btn btn-primary-gradient px-5 py-3 rounded-pill"><i class="fa-regular fa-paper-plane me-2"></i> Send Message</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Include Swiper JS & AOS Library in the footer context -->
<script src="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.js"></script>
<script src="https://unpkg.com/aos@next/dist/aos.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Initialize AOS animations
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true,
            mirror: false
        });

        // Initialize Swiper Carousel
        new Swiper('.swiper-testimonials', {
            slidesPerView: 1,
            spaceBetween: 20,
            pagination: {
                el: '.swiper-pagination',
                clickable: true,
            },
            breakpoints: {
                768: {
                    slidesPerView: 2,
                    spaceBetween: 30,
                },
                1024: {
                    slidesPerView: 3,
                    spaceBetween: 30,
                }
            },
            autoplay: {
                delay: 4000,
                disableOnInteraction: false,
            }
        });

        // Counter Animation
        const counters = document.querySelectorAll('.counter-number');
        const speed = 200;

        const startCounters = () => {
            counters.forEach(counter => {
                const target = parseInt(counter.getAttribute('data-target'));
                const suffix = counter.innerText.includes('+') ? '+' : '';
                
                const updateCount = () => {
                    const current = parseInt(counter.innerText);
                    const increment = Math.ceil(target / speed);
                    
                    if (current < target) {
                        counter.innerText = (current + increment) + suffix;
                        setTimeout(updateCount, 15);
                    } else {
                        // format with commas
                        counter.innerText = target.toLocaleString() + suffix;
                    }
                };
                
                counter.innerText = '0' + suffix;
                updateCount();
            });
        };

        // Trigger counters when scrolled into view
        const targetSection = document.querySelector('.counter-section');
        if (targetSection) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        startCounters();
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            observer.observe(targetSection);
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
