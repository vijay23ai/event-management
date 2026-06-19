<?php
$page_title = "Discover Events";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Categories list
$categories = ['Concert', 'Workshop', 'Sports', 'Seminar', 'Festival', 'Meetup'];

// Fetch filters
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$city = trim($_GET['city'] ?? '');
$date = trim($_GET['date'] ?? '');
$price_min = trim($_GET['price_min'] ?? '');
$price_max = trim($_GET['price_max'] ?? '');

// Base query for event discovery
$query_parts = ["e.status = 'approved'"];
$params = [];

if (!empty($search)) {
    $query_parts[] = "(e.title LIKE ? OR e.description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if (!empty($category)) {
    $query_parts[] = "e.category = ?";
    $params[] = $category;
}
if (!empty($city)) {
    $query_parts[] = "e.city = ?";
    $params[] = $city;
}
if (!empty($date)) {
    $query_parts[] = "DATE(e.date_time) = ?";
    $params[] = $date;
}
if ($price_min !== '') {
    $query_parts[] = "tt.price >= ?";
    $params[] = (float)$price_min;
}
if ($price_max !== '') {
    $query_parts[] = "tt.price <= ?";
    $params[] = (float)$price_max;
}

$where_clause = implode(" AND ", $query_parts);

// Fetch all filtered events
$sql = "SELECT DISTINCT e.*, 
               (SELECT MIN(price) FROM tickets_types WHERE event_id = e.id) as min_price,
               (SELECT AVG(rating) FROM reviews WHERE event_id = e.id) as avg_rating,
               (SELECT COUNT(id) FROM reviews WHERE event_id = e.id) as count_reviews
        FROM events e 
        LEFT JOIN tickets_types tt ON e.id = tt.event_id 
        WHERE {$where_clause} 
        ORDER BY e.date_time ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filtered_events = $stmt->fetchAll();
    
    // Fetch unique cities for city filter dropdown
    $city_stmt = $pdo->query("SELECT DISTINCT city FROM events WHERE status = 'approved' AND city != ''");
    $cities = $city_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch Featured Events (Earliest 3 upcoming events)
    $feat_stmt = $pdo->query("SELECT e.*, 
                                     (SELECT MIN(price) FROM tickets_types WHERE event_id = e.id) as min_price,
                                     (SELECT AVG(rating) FROM reviews WHERE event_id = e.id) as avg_rating,
                                     (SELECT COUNT(id) FROM reviews WHERE event_id = e.id) as count_reviews
                              FROM events e 
                              WHERE e.status = 'approved' AND e.date_time >= NOW() 
                              ORDER BY e.date_time ASC LIMIT 3");
    $featured_events = $feat_stmt->fetchAll();

    // Fetch Trending Events (Events with the most confirmed bookings)
    $trend_stmt = $pdo->query("SELECT e.*, 
                                      COUNT(b.id) as bookings_count,
                                      (SELECT MIN(price) FROM tickets_types WHERE event_id = e.id) as min_price,
                                      (SELECT AVG(rating) FROM reviews WHERE event_id = e.id) as avg_rating,
                                      (SELECT COUNT(id) FROM reviews WHERE event_id = e.id) as count_reviews
                               FROM events e 
                               LEFT JOIN bookings b ON e.id = b.event_id AND b.status = 'confirmed' 
                               WHERE e.status = 'approved' 
                               GROUP BY e.id 
                               ORDER BY bookings_count DESC, e.date_time ASC 
                               LIMIT 3");
    $trending_events = $trend_stmt->fetchAll();

} catch (PDOException $e) {
    die("Database query error: " . $e->getMessage());
}

function render_event_card($event) {
    $min_price = $event['min_price'] ?? 0;
    $avg_rating = round($event['avg_rating'] ?? 0, 1);
    $count_reviews = $event['count_reviews'] ?? 0;
    $is_sold_out = ($event['remaining_seats'] <= 0);
    $is_upcoming = (strtotime($event['date_time']) > time());
    
    $cat_icons = [
        'concert' => 'bi-music-note-beamed',
        'workshop' => 'bi-tools',
        'sports' => 'bi-trophy-fill',
        'seminar' => 'bi-journal-code',
        'festival' => 'bi-emoji-laughing-fill',
        'meetup' => 'bi-people-fill',
        'exhibition' => 'bi-image',
        'cultural event' => 'bi-palette',
        'corporate event' => 'bi-briefcase'
    ];
    $cat_key = strtolower($event['category']);
    $icon = $cat_icons[$cat_key] ?? 'bi-tag-fill';
    
    $badge_class = 'badge-indigo';
    if (in_array($cat_key, ['sports', 'festival'])) {
        $badge_class = 'badge-sky';
    }
    
    $formatted_date = date('M d, Y', strtotime($event['date_time']));
    $formatted_price = '₹' . number_format($min_price, 0);
    ?>
    <div class="col-md-4 mb-4 d-flex">
        <div class="glass-card h-100 d-flex flex-column animate__animated animate__fadeInUp w-100">
            <div class="position-relative overflow-hidden" style="height: 180px;">
                <img src="<?php echo $event['banner_image'] ?: 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&q=80&w=800'; ?>" class="w-100 h-100 card-img-top-hover" alt="<?php echo htmlspecialchars($event['title']); ?>" style="object-fit: cover; transition: transform 0.5s ease;">
                <div class="position-absolute top-0 end-0 m-3">
                    <?php if ($avg_rating > 0): ?>
                        <span class="badge bg-dark bg-opacity-75 text-warning fw-bold fs-6 border border-secondary"><i class="bi bi-star-fill me-1"></i><?php echo $avg_rating; ?> <span class="text-white font-monospace" style="font-size: 0.7rem;">(<?php echo $count_reviews; ?>)</span></span>
                    <?php else: ?>
                        <span class="badge bg-dark bg-opacity-75 text-secondary fw-bold border border-secondary">New</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4 d-flex flex-column flex-grow-1">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="badge badge-custom <?php echo $badge_class; ?>"><i class="bi <?php echo $icon; ?> me-1"></i><?php echo $event['category']; ?></span>
                    <span class="small text-secondary"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($event['city']); ?></span>
                </div>
                <h5 class="fw-bold mb-2 text-truncate" style="color: var(--text-primary);" title="<?php echo htmlspecialchars($event['title']); ?>"><?php echo htmlspecialchars($event['title']); ?></h5>
                <p class="text-secondary small mb-3 flex-grow-1 text-truncate-3"><?php echo htmlspecialchars($event['description']); ?></p>
                
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="text-muted small d-block">Starting from</span>
                        <strong class="text-indigo fs-5" style="color: #6366f1;"><?php echo $formatted_price; ?></strong>
                    </div>
                    <div class="text-end">
                        <?php if ($is_sold_out): ?>
                            <span class="badge bg-danger">Sold Out</span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><?php echo $event['remaining_seats']; ?> left</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-auto pt-3 border-top border-secondary gap-2">
                    <span class="small text-secondary"><i class="bi bi-calendar3 me-1"></i><?php echo $formatted_date; ?></span>
                    <div class="d-flex gap-2">
                        <a href="event.php?id=<?php echo $event['id']; ?>" class="btn btn-glass btn-sm px-3">Details</a>
                        <?php if ($is_upcoming && !$is_sold_out && $event['status'] === 'approved'): ?>
                            <a href="book.php?event_id=<?php echo $event['id']; ?>" class="btn btn-primary-gradient btn-sm px-3">Book</a>
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

<!-- Hero / Search Section -->
<div class="row mb-5">
    <div class="col-12">
        <div class="glass-panel p-5 text-center position-relative overflow-hidden" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(14, 165, 233, 0.1) 100%); border-color: rgba(99, 102, 241, 0.2);">
            <div class="position-absolute top-0 start-0 w-100 h-100 bg-grid" style="opacity: 0.05;"></div>
            <h1 class="display-4 fw-bold mb-3" style="color: var(--text-primary);">Discover City-Wide Events</h1>
            <p class="lead text-secondary mb-4 mx-auto" style="max-width: 600px;">Explore concerts, technology workshops, sports matches, and community meetups happening right in your city.</p>
            
            <form action="discover.php" method="GET" class="row g-3 justify-content-center mx-auto" style="max-width: 900px;">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text border-secondary" style="background: var(--btn-glass-bg); color: var(--text-secondary);"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control form-control-glass" placeholder="Search by name, description..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="city" class="form-select form-select-glass">
                        <option value="">All Cities</option>
                        <?php foreach ($cities as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $city === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="category" class="form-select form-select-glass">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $category === $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary-gradient w-100 py-2"><i class="bi bi-search me-2"></i>Find</button>
                </div>
                
                <!-- Advanced filters toggler -->
                <div class="col-12 mt-2 text-end">
                    <button class="btn btn-link text-indigo text-decoration-none p-0 small" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters" aria-expanded="false" style="color: #a5b4fc;">
                        <i class="bi bi-sliders me-1"></i> More Filters
                    </button>
                </div>
                
                <!-- Advanced Filters Collapse -->
                <div class="collapse col-12 <?php echo ($date !== '' || $price_min !== '' || $price_max !== '') ? 'show' : ''; ?>" id="advancedFilters">
                    <div class="card card-body bg-dark border-secondary p-4 mt-2 text-start">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="date" class="form-label-glass">Date</label>
                                <input type="date" name="date" id="date" class="form-control form-control-glass" value="<?php echo htmlspecialchars($date); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="price_min" class="form-label-glass">Min Price (₹)</label>
                                <input type="number" name="price_min" id="price_min" class="form-control form-control-glass" placeholder="0" value="<?php echo htmlspecialchars($price_min); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="price_max" class="form-label-glass">Max Price (₹)</label>
                                <input type="number" name="price_max" id="price_max" class="form-control form-control-glass" placeholder="500" value="<?php echo htmlspecialchars($price_max); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Categories Quick Links -->
<div class="row mb-5">
    <div class="col-12">
        <h4 class="fw-bold mb-4">Browse Categories</h4>
        <div class="row g-3">
            <?php 
            $cat_icons = [
                'Concert' => 'bi-music-note-beamed',
                'Workshop' => 'bi-tools',
                'Sports' => 'bi-trophy-fill',
                'Seminar' => 'bi-journal-code',
                'Festival' => 'bi-emoji-laughing-fill',
                'Meetup' => 'bi-people-fill'
            ];
            foreach ($categories as $cat): 
                $icon = $cat_icons[$cat] ?? 'bi-tag-fill';
                $is_active = ($category === $cat);
            ?>
                <div class="col-6 col-md-2">
                    <a href="discover.php?category=<?php echo urlencode($cat); ?>" class="text-decoration-none">
                        <div class="glass-panel text-center py-4 <?php echo $is_active ? 'border-primary' : ''; ?>" style="background: <?php echo $is_active ? 'rgba(99, 102, 241, 0.25)' : 'var(--glass-bg)'; ?>;">
                            <i class="bi <?php echo $icon; ?> fs-2 mb-2 d-block" style="color: <?php echo $is_active ? 'var(--accent-indigo)' : 'var(--accent-indigo)'; ?>;"></i>
                            <span class="fw-bold small" style="color: var(--text-primary);"><?php echo $cat; ?></span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (empty($search) && empty($category) && empty($city) && empty($date) && empty($price_min) && empty($price_max)): ?>
    <!-- Featured Events (Only show when not searching) -->
    <div class="row mb-5">
        <div class="col-12 d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Featured Events</h4>
            <span class="badge badge-custom badge-indigo">Recommended</span>
        </div>
        <div class="row g-4">
            <?php if (empty($featured_events)): ?>
                <div class="col-12"><p class="text-secondary">No featured events available right now.</p></div>
            <?php else: ?>
                <?php foreach ($featured_events as $event): ?>
                    <?php render_event_card($event); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Trending Events (Most bookings) -->
    <div class="row mb-5">
        <div class="col-12 d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Trending Events</h4>
            <span class="badge badge-custom badge-sky">High Demand</span>
        </div>
        <div class="row g-4">
            <?php if (empty($trending_events)): ?>
                <div class="col-12"><p class="text-secondary">No trending events detected yet.</p></div>
            <?php else: ?>
                <?php foreach ($trending_events as $event): ?>
                    <?php render_event_card($event); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Search Results / All Events -->
<div class="row mb-5">
    <div class="col-12 mb-4">
        <h4 class="fw-bold">
            <?php 
            if (!empty($search) || !empty($category) || !empty($city) || !empty($date) || $price_min !== '' || $price_max !== '') {
                echo "Search Results (" . count($filtered_events) . ")";
            } else {
                echo "All Upcoming Events";
            }
            ?>
        </h4>
    </div>
    
    <div class="row g-4">
        <?php if (empty($filtered_events)): ?>
            <div class="col-12 text-center py-5">
                <i class="bi bi-calendar-x fs-1 text-secondary"></i>
                <h5 class="mt-3" style="color: var(--text-primary);">No Events Found</h5>
                <p class="text-secondary">Try adjusting your filters or search keywords.</p>
                <a href="discover.php" class="btn btn-glass">Clear All Filters</a>
            </div>
        <?php else: ?>
            <?php foreach ($filtered_events as $event): ?>
                <?php render_event_card($event); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.text-truncate-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>