<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($event_id <= 0) {
    $_SESSION['flash_error'] = "Invalid event ID.";
    header('Location: /discover.php');
    exit;
}

try {
    // 1. Fetch Event and Organizer details
    $stmt = $pdo->prepare("SELECT e.*, u.name as organizer_name, u.email as organizer_email, u.profile_image as organizer_image
                           FROM events e 
                           JOIN users u ON e.organizer_id = u.id 
                           WHERE e.id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    
    if (!$event) {
        $_SESSION['flash_error'] = "Event not found.";
        header('Location: /discover.php');
        exit;
    }

    // 2. Fetch Ticket Types
    $ticket_stmt = $pdo->prepare("SELECT * FROM tickets_types WHERE event_id = ? ORDER BY price ASC");
    $ticket_stmt->execute([$event_id]);
    $ticket_types = $ticket_stmt->fetchAll();

    // 3. Fetch Reviews
    $rev_stmt = $pdo->prepare("SELECT r.*, u.name as user_name FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.event_id = ? ORDER BY r.created_at DESC");
    $rev_stmt->execute([$event_id]);
    $reviews = $rev_stmt->fetchAll();

    // 4. Calculate Average Rating
    $avg_stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(id) as count_reviews FROM reviews WHERE event_id = ?");
    $avg_stmt->execute([$event_id]);
    $rating_stats = $avg_stmt->fetch();
    $avg_rating = round($rating_stats['avg_rating'] ?? 0, 1);
    $review_count = $rating_stats['count_reviews'] ?? 0;

    // 5. Check if current user can submit a review
    $can_review = false;
    $logged_user = get_logged_in_user();
    if ($logged_user && $logged_user['role'] === 'user') {
        $check_book = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND event_id = ? AND status = 'confirmed'");
        $check_book->execute([$logged_user['id'], $event_id]);
        if ($check_book->fetchColumn() > 0) {
            // Also verify they haven't reviewed already
            $check_rev = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE user_id = ? AND event_id = ?");
            $check_rev->execute([$logged_user['id'], $event_id]);
            if ($check_rev->fetchColumn() == 0) {
                $can_review = true;
            }
        }
    }

    // 6. Fetch Related Events (in the same category, excluding current event)
    $related_stmt = $pdo->prepare("SELECT e.*, u.name as organizer_name, 
                                          (SELECT MIN(price) FROM tickets_types WHERE event_id = e.id) as min_price
                                   FROM events e
                                   LEFT JOIN users u ON e.organizer_id = u.id
                                   WHERE e.status = 'approved' AND e.category = ? AND e.id != ? AND e.date_time >= NOW() 
                                   ORDER BY e.date_time ASC LIMIT 3");
    $related_stmt->execute([$event['category'], $event_id]);
    $related_events = $related_stmt->fetchAll();

    // If not enough related events, fill with other upcoming events
    if (count($related_events) < 3) {
        $needed = 3 - count($related_events);
        $exclude_ids = array_merge([$event_id], array_column($related_events, 'id'));
        $in_clause = implode(',', array_fill(0, count($exclude_ids), '?'));
        
        $fill_stmt = $pdo->prepare("SELECT e.*, u.name as organizer_name,
                                           (SELECT MIN(price) FROM tickets_types WHERE event_id = e.id) as min_price
                                    FROM events e
                                    LEFT JOIN users u ON e.organizer_id = u.id
                                    WHERE e.status = 'approved' AND e.id NOT IN ($in_clause) AND e.date_time >= NOW() 
                                    ORDER BY e.date_time ASC LIMIT $needed");
        $fill_stmt->execute($exclude_ids);
        $related_events = array_merge($related_events, $fill_stmt->fetchAll());
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// 7. Get Google Maps API Key from settings
$google_maps_key = '';
try {
    $set_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'google_maps_api_key'");
    $set_stmt->execute();
    $google_maps_key = $set_stmt->fetchColumn() ?: '';
} catch (PDOException $e) {
    // Fail silently
}

// Map city to coordinates fallback
$lat = $event['latitude'] ?? null;
$lng = $event['longitude'] ?? null;
if (empty($lat) || empty($lng)) {
    $city_coords = [
        'san francisco' => [37.7749, -122.4194],
        'new york' => [40.7128, -74.0060],
        'chicago' => [41.8781, -87.6298],
        'los angeles' => [34.0522, -118.2437],
        'mumbai' => [19.0760, 72.8777],
        'delhi' => [28.7041, 77.1025],
        'bangalore' => [12.9716, 77.5946],
        'hyderabad' => [17.3850, 78.4867],
        'chennai' => [13.0827, 80.2707],
        'kolkata' => [22.5726, 88.3639],
        'pune' => [18.5204, 73.8567]
    ];
    $target_city = strtolower($event['city']);
    $coords = $city_coords[$target_city] ?? [19.0760, 72.8777];
    $lat = $coords[0];
    $lng = $coords[1];
}

// Construct full address
$full_address = '';
if (!empty($event['building_name'])) {
    $full_address .= $event['building_name'] . ', ';
}
$full_address .= $event['address'] . ', ' . $event['city'];
if (!empty($event['state'])) {
    $full_address .= ', ' . $event['state'];
}
if (!empty($event['pincode'])) {
    $full_address .= ' - ' . $event['pincode'];
}

// Map rendering libraries (Loads Google Maps if key is set, else Leaflet fallback)
$additional_styles = '';
if (!empty($google_maps_key)) {
    $additional_styles .= '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($google_maps_key) . '&libraries=places"></script>';
} else {
    $additional_styles .= '
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    ';
}

$page_title = $event['title'];
require_once __DIR__ . '/includes/header.php';
?>

<!-- Banner Image -->
<div class="row">
    <div class="col-12 animate__animated animate__fadeIn">
        <div class="event-detail-banner">
            <img src="<?php echo $event['banner_image'] ?: 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&q=80&w=800'; ?>" class="event-detail-banner-img" alt="<?php echo htmlspecialchars($event['title']); ?>">
            <div class="event-detail-banner-overlay"></div>
        </div>
    </div>
</div>

<!-- Main Event Info Container -->
<div class="row px-md-4 mb-5 position-relative" style="z-index: 5;">
    <div class="col-lg-8 animate__animated animate__fadeInLeft">
        <div class="glass-panel p-4 p-md-5 mb-4">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <span class="badge badge-custom badge-indigo"><i class="bi bi-tag me-1"></i><?php echo $event['category']; ?></span>
                <span class="text-secondary small"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($event['city']); ?></span>
                
                <?php if ($event['status'] === 'cancelled'): ?>
                    <span class="badge bg-danger animate__animated animate__flash animate__infinite">CANCELLED</span>
                <?php endif; ?>
            </div>
            
            <h1 class="fw-bold text-white mb-4 display-5"><?php echo htmlspecialchars($event['title']); ?></h1>
            
            <div class="d-flex flex-wrap gap-4 text-secondary mb-4 pb-4 border-bottom border-secondary">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-event fs-4 text-indigo"></i>
                    <div>
                        <div class="small text-muted">Date & Time</div>
                        <div class="fw-bold" style="color:var(--text-primary);"><?php echo date('F d, Y \a\t h:i A', strtotime($event['date_time'])); ?></div>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-building fs-4 text-indigo"></i>
                    <div>
                        <div class="small text-muted">Venue</div>
                        <div class="fw-bold" style="color:var(--text-primary);"><?php echo htmlspecialchars($event['venue']); ?></div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <div class="organizer-avatar" style="width:40px;height:40px;font-size:1rem;">
                        <?php if (!empty($event['organizer_image'])): ?>
                            <img src="<?php echo htmlspecialchars($event['organizer_image']); ?>" alt="<?php echo htmlspecialchars($event['organizer_name']); ?>">
                        <?php else: ?>
                            <?php echo strtoupper(substr($event['organizer_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="small text-muted">Organizer</div>
                        <div class="fw-bold" style="color:var(--text-primary);"><?php echo htmlspecialchars($event['organizer_name']); ?></div>
                    </div>
                </div>
            </div>
            
            <h4 class="fw-bold text-white mb-3">About This Event</h4>
            <p class="text-secondary lead-sm" style="line-height: 1.8; white-space: pre-line;"><?php echo htmlspecialchars($event['description']); ?></p>
            
            <!-- Location & Venue Map -->
            <h4 class="fw-bold text-white mt-5 mb-3">Location & Venue Map</h4>
            <div class="p-4 rounded-4 mb-4" style="background: rgba(15, 23, 42, 0.4) !important; border: 1px solid var(--glass-border);">
                <div class="text-white fw-bold mb-1"><i class="bi bi-geo-alt-fill text-indigo me-2"></i><?php echo htmlspecialchars($event['venue']); ?></div>
                <div class="text-secondary mb-3 small" style="margin-left: 24px;"><?php echo htmlspecialchars($full_address); ?></div>
                
                <div id="event-map" class="mb-4"></div>
                
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="d-flex gap-2">
                        <?php 
                        $gmaps_link = $event['google_maps_link'] ?: "https://www.google.com/maps/search/?api=1&query=" . urlencode($event['venue'] . ' ' . $full_address);
                        $directions_link = "https://www.google.com/maps/dir/?api=1&destination=" . $lat . "," . $lng;
                        ?>
                        <a href="<?php echo htmlspecialchars($gmaps_link); ?>" target="_blank" class="btn btn-glass btn-sm">
                            <i class="bi bi-box-arrow-up-right me-1"></i> View on Google Maps
                        </a>
                        <a href="<?php echo htmlspecialchars($directions_link); ?>" target="_blank" class="btn btn-glass btn-sm text-indigo">
                            <i class="bi bi-map-fill me-1"></i> Get Directions
                        </a>
                    </div>
                    <div>
                        <button onclick="calculateUserDistance(<?php echo $lat; ?>, <?php echo $lng; ?>)" class="btn btn-primary-gradient btn-sm">
                            <i class="bi bi-cursor-fill me-1"></i> View Distance
                        </button>
                        <span id="distance-display" class="badge badge-custom badge-indigo d-none ms-2"></span>
                    </div>
                </div>
            </div>

            <!-- Event Media Gallery & Videos -->
            <h4 class="fw-bold text-white mt-5 mb-3">Event Gallery</h4>
            <div class="row g-3 mb-4">
                <div class="col-md-4 col-6">
                    <img src="https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&q=80&w=400" class="img-fluid rounded-4 gallery-img" alt="Gallery 1" style="height: 140px; object-fit: cover; width: 100%; cursor: pointer;" onclick="viewGalleryImage(this.src)">
                </div>
                <div class="col-md-4 col-6">
                    <img src="https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&q=80&w=400" class="img-fluid rounded-4 gallery-img" alt="Gallery 2" style="height: 140px; object-fit: cover; width: 100%; cursor: pointer;" onclick="viewGalleryImage(this.src)">
                </div>
                <div class="col-md-4 col-12">
                    <div class="position-relative rounded-4 overflow-hidden" style="height: 140px;">
                        <img src="https://images.unsplash.com/photo-1475721027785-f74eccf877e2?auto=format&fit=crop&q=80&w=400" class="img-fluid w-100 h-100" style="object-fit: cover;" alt="Gallery 3">
                        <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-dark bg-opacity-70">
                            <button class="btn btn-glass btn-sm rounded-circle p-2" onclick="playEventTeaser()"><i class="bi bi-play-fill fs-4 text-white"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reviews Section -->
        <div class="glass-panel p-4 p-md-5 mb-4">
            <h4 class="fw-bold text-white mb-4">Attendee Reviews</h4>
            
            <div class="row align-items-center mb-4 pb-4 border-bottom border-secondary">
                <div class="col-md-4 text-center border-end border-secondary mb-3 mb-md-0">
                    <div class="display-3 fw-bold text-white"><?php echo $avg_rating; ?></div>
                    <div class="text-warning mb-1">
                        <?php 
                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= round($avg_rating)) {
                                echo '<i class="bi bi-star-fill me-1"></i>';
                            } else {
                                echo '<i class="bi bi-star me-1"></i>';
                            }
                        }
                        ?>
                    </div>
                    <div class="text-muted small">Based on <?php echo $review_count; ?> reviews</div>
                </div>
                
                <div class="col-md-8 px-md-4">
                    <h5 class="fw-bold text-white mb-2">Write a Review</h5>
                    <?php if ($can_review): ?>
                        <form action="submit_review.php" method="POST">
                            <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                            <div class="mb-3">
                                <label class="form-label-glass">Your Rating</label>
                                <div class="rating-stars text-warning fs-4">
                                    <input type="radio" name="rating" value="5" id="r5" required class="d-none"><label for="r5" class="bi bi-star pointer me-1" onclick="highlightStars(5)"></label>
                                    <input type="radio" name="rating" value="4" id="r4" class="d-none"><label for="r4" class="bi bi-star pointer me-1" onclick="highlightStars(4)"></label>
                                    <input type="radio" name="rating" value="3" id="r3" class="d-none"><label for="r3" class="bi bi-star pointer me-1" onclick="highlightStars(3)"></label>
                                    <input type="radio" name="rating" value="2" id="r2" class="d-none"><label for="r2" class="bi bi-star pointer me-1" onclick="highlightStars(2)"></label>
                                    <input type="radio" name="rating" value="1" id="r1" class="d-none"><label for="r1" class="bi bi-star pointer me-1" onclick="highlightStars(1)"></label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <textarea name="comment" class="form-control form-control-glass" rows="2" placeholder="Write your feedback..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary-gradient btn-sm px-4">Submit Review</button>
                        </form>
                    <?php elseif (!$logged_user): ?>
                        <p class="text-secondary small mb-0"><i class="bi bi-info-circle me-1"></i> Please <a href="login.php" class="text-indigo text-decoration-none">login</a> to leave a review.</p>
                    <?php elseif ($logged_user['role'] !== 'user'): ?>
                        <p class="text-secondary small mb-0"><i class="bi bi-info-circle me-1"></i> Reviews are only available to Attendees.</p>
                    <?php else: ?>
                        <p class="text-secondary small mb-0"><i class="bi bi-info-circle me-1"></i> You must purchase a ticket for this event to leave a review.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Review list -->
            <?php if (empty($reviews)): ?>
                <p class="text-secondary text-center py-3 mb-0">No reviews yet. Be the first to attend and review!</p>
            <?php else: ?>
                <div class="review-list d-flex flex-column gap-3">
                    <?php foreach ($reviews as $rev): ?>
                        <div class="p-3 rounded-4 bg-dark" style="background: rgba(15, 23, 42, 0.4) !important; border: 1px solid var(--glass-border);">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-bold text-white small"><?php echo htmlspecialchars($rev['user_name']); ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;"><?php echo date('M d, Y', strtotime($rev['created_at'])); ?></div>
                            </div>
                            <div class="text-warning small mb-2">
                                <?php 
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $rev['rating'] ? '<i class="bi bi-star-fill me-1"></i>' : '<i class="bi bi-star me-1"></i>';
                                }
                                Pacers: ?>
                            </div>
                            <p class="text-secondary small mb-0"><?php echo htmlspecialchars($rev['comment']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Booking Sidebar -->
    <div class="col-lg-4 mt-4 mt-lg-0 animate__animated animate__fadeInRight" id="booking-section-anchor">
        <div class="glass-panel p-4 position-sticky animate__animated animate__fadeInUp" style="top: 100px;">
            <h4 class="fw-bold text-white mb-4">Select Tickets</h4>
            
            <?php if ($event['status'] === 'cancelled'): ?>
                <div class="alert alert-danger text-center fw-bold">EVENT IS CANCELLED</div>
            <?php elseif ($event['remaining_seats'] <= 0): ?>
                <div class="alert alert-danger text-center fw-bold">EVENT IS SOLD OUT</div>
            <?php else: ?>
                <form action="book.php" method="GET">
                    <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                    
                    <div class="d-flex flex-column gap-3 mb-4">
                        <?php foreach ($ticket_types as $tt): 
                            $is_sold_out = ($tt['remaining_seats'] <= 0);
                        ?>
                            <label class="d-block p-3 rounded-4 bg-dark border pointer position-relative <?php echo $is_sold_out ? 'opacity-50' : ''; ?>" 
                                   style="background: rgba(15, 23, 42, 0.5) !important; border-color: var(--glass-border);" 
                                   id="label-tt-<?php echo $tt['id']; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-3">
                                        <input type="radio" name="ticket_type_id" value="<?php echo $tt['id']; ?>" <?php echo $is_sold_out ? 'disabled' : 'required'; ?> class="form-check-input" onclick="highlightTicketOption(<?php echo $tt['id']; ?>)">
                                        <div>
                                            <div class="fw-bold text-white"><?php echo $tt['name']; ?> Ticket</div>
                                            <div class="text-muted small"><?php echo $tt['remaining_seats']; ?> seats left</div>
                                        </div>
                                    </div>
                                    <div class="text-indigo fw-bold fs-5" style="color: #6366f1;">₹<?php echo number_format($tt['price'], 0); ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="mb-4">
                        <label for="quantity" class="form-label-glass">Quantity</label>
                        <select name="quantity" id="quantity" class="form-select form-select-glass" required>
                            <option value="1">1 Ticket</option>
                            <option value="2">2 Tickets</option>
                            <option value="3">3 Tickets</option>
                            <option value="4">4 Tickets</option>
                            <option value="5">5 Tickets</option>
                        </select>
                    </div>

                    <?php if ($logged_user && $logged_user['role'] !== 'user'): ?>
                        <div class="alert alert-warning small mb-0 text-center">
                            <i class="bi bi-info-circle me-1"></i> You are logged in as <?php echo $logged_user['role']; ?>. Bookings are only permitted for attendees.
                        </div>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary-gradient w-100 py-3 fw-bold">
                            <i class="bi bi-cart-plus me-2"></i> Book Tickets Now
                        </button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <div class="border-top border-secondary mt-4 pt-3 text-secondary small">
                <div class="d-flex justify-content-between mb-2">
                    <span>Refund Policy:</span>
                    <span class="text-white">Cancellations allowed</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Support:</span>
                    <a href="mailto:<?php echo htmlspecialchars($event['organizer_email']); ?>" class="text-indigo text-decoration-none" style="color: #a5b4fc;"><?php echo htmlspecialchars($event['organizer_name']); ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Suggestions / Related Events -->
<div class="row px-md-4 mb-5">
    <div class="col-12">
        <h3 class="fw-bold mb-4" style="color:var(--text-primary);"><i class="bi bi-heart-fill text-danger me-2"></i>You Might Also Like</h3>
        <div class="row g-4">
            <?php if (empty($related_events)): ?>
                <div class="col-12"><p class="text-secondary">No other events found.</p></div>
            <?php else: ?>
                <?php foreach ($related_events as $rev): 
                    $rev_thumb = !empty($rev['banner_image']) ? htmlspecialchars($rev['banner_image']) : 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&q=80&w=800';
                    $rev_org_initial = strtoupper(substr($rev['organizer_name'] ?? 'O', 0, 1));
                    $rev_price = !empty($rev['min_price']) ? '₹' . number_format($rev['min_price'], 0) : 'Free';
                ?>
                    <div class="col-md-4 d-flex">
                        <div class="event-card-v2 w-100">
                            <div class="card-image-wrap">
                                <img src="<?php echo $rev_thumb; ?>" alt="<?php echo htmlspecialchars($rev['title']); ?>" loading="lazy">
                                <div class="card-img-overlay-gradient"></div>
                                <div class="card-badge-corner">
                                    <span class="badge badge-custom badge-indigo"><?php echo htmlspecialchars($rev['category']); ?></span>
                                </div>
                            </div>
                            <div class="card-body-inner">
                                <div class="organizer-row">
                                    <div class="organizer-avatar"><?php echo $rev_org_initial; ?></div>
                                    <span class="organizer-name"><?php echo htmlspecialchars($rev['organizer_name'] ?? 'Organizer'); ?></span>
                                </div>
                                <div class="event-title"><?php echo htmlspecialchars($rev['title']); ?></div>
                                <div class="event-meta-row"><i class="bi bi-calendar3"></i><span><?php echo date('M d, Y', strtotime($rev['date_time'])); ?></span></div>
                                <div class="event-meta-row"><i class="bi bi-geo-alt-fill"></i><span><?php echo htmlspecialchars($rev['city']); ?></span></div>
                                <div class="card-footer-bar">
                                    <div>
                                        <div class="event-price-label">From</div>
                                        <div class="event-price"><?php echo $rev_price; ?></div>
                                    </div>
                                    <a href="/event.php?id=<?php echo $rev['id']; ?>" class="btn btn-glass btn-sm rounded-pill px-3">View Details</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sticky bottom bar for mobile screens -->
<div class="d-lg-none fixed-bottom border-top border-secondary p-3 z-3 no-print" style="background: rgba(15, 23, 42, 0.9) !important; backdrop-filter: blur(10px);">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <div class="small text-muted" style="font-size:0.75rem;">Prices Starting From</div>
            <div class="text-indigo fw-bold fs-5" style="color: #6366f1;">
                <?php 
                $prices = array_column($ticket_types, 'price');
                $min_price = !empty($prices) ? min($prices) : 0;
                echo '₹' . number_format($min_price, 0); 
                ?>
            </div>
        </div>
        <button onclick="scrollToBooking()" class="btn btn-primary-gradient px-4 py-2 fw-bold">Book Now</button>
    </div>
</div>

<!-- Image View Modal -->
<div class="modal fade" id="galleryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-body text-end p-0">
                <button type="button" class="btn-close btn-close-white mb-2" data-bs-dismiss="modal" aria-label="Close"></button>
                <img id="modalGalleryImg" src="" class="img-fluid rounded-4 w-100" alt="Full Image">
            </div>
        </div>
    </div>
</div>

<!-- Teaser Video Modal -->
<div class="modal fade" id="videoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold">Event Teaser</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="ratio ratio-16x9">
                    <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=0" title="YouTube video" allowfullscreen></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Leaflet or Google Maps Initialization
document.addEventListener("DOMContentLoaded", function() {
    var lat = <?php echo $lat; ?>;
    var lng = <?php echo $lng; ?>;
    var venueName = <?php echo json_encode($event['venue']); ?>;
    var fullAddress = <?php echo json_encode($full_address); ?>;

    if (window.google && window.google.maps) {
        // Render Google Map
        var mapOptions = {
            center: { lat: lat, lng: lng },
            zoom: 14,
            styles: [
                {
                    "featureType": "all",
                    "elementType": "labels.text.fill",
                    "stylers": [{ "color": "#747d8c" }]
                }
            ]
        };
        var map = new google.maps.Map(document.getElementById('event-map'), mapOptions);
        var marker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: map,
            title: venueName
        });
        var infowindow = new google.maps.InfoWindow({
            content: '<b style="color:#000;">' + venueName + '</b><br><span style="color:#333;">' + fullAddress + '</span>'
        });
        marker.addListener('click', function() {
            infowindow.open(map, marker);
        });
        infowindow.open(map, marker);
    } else {
        // Fallback to Leaflet map
        var map = L.map('event-map').setView([lat, lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        L.marker([lat, lng]).addTo(map)
            .bindPopup('<b>' + venueName + '</b><br>' + fullAddress)
            .openPopup();
    }
});

function highlightTicketOption(id) {
    // Clear other border highlight
    document.querySelectorAll('[id^="label-tt-"]').forEach(el => {
        el.style.borderColor = 'var(--glass-border)';
        el.style.boxShadow = 'none';
    });
    
    // Highlight selected
    let selectedLabel = document.getElementById('label-tt-' + id);
    if (selectedLabel) {
        selectedLabel.style.borderColor = '#6366f1';
        selectedLabel.style.boxShadow = '0 0 10px rgba(99, 102, 241, 0.2)';
    }
}

function highlightStars(rating) {
    for (let i = 1; i <= 5; i++) {
        let star = document.querySelector('label[for="r' + i + '"]');
        if (star) {
            if (i <= rating) {
                star.className = 'bi bi-star-fill pointer me-1';
            } else {
                star.className = 'bi bi-star pointer me-1';
            }
        }
    }
    document.getElementById('r' + rating).checked = true;
}

function calculateUserDistance(destLat, destLng) {
    const display = document.getElementById('distance-display');
    display.classList.remove('d-none');
    display.innerHTML = '<i class="spinner-border spinner-border-sm me-1"></i> Locating...';
    
    if (!navigator.geolocation) {
        display.className = 'badge bg-danger ms-2';
        display.innerText = 'Geolocation not supported';
        return;
    }
    
    navigator.geolocation.getCurrentPosition(
        (position) => {
            const userLat = position.coords.latitude;
            const userLng = position.coords.longitude;
            
            const R = 6371; // Earth radius in km
            const dLat = (destLat - userLat) * Math.PI / 180;
            const dLon = (destLng - userLng) * Math.PI / 180;
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(userLat * Math.PI / 180) * Math.cos(destLat * Math.PI / 180) * 
                Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            const d = R * c;
            
            display.className = 'badge badge-custom badge-indigo animate__animated animate__fadeIn ms-2';
            display.innerHTML = `<i class="bi bi-car-front-fill me-1"></i> ${d.toFixed(1)} km away`;
        },
        (error) => {
            display.className = 'badge bg-danger ms-2';
            display.innerText = 'Unable to get location';
            console.error(error);
        }
    );
}

function viewGalleryImage(src) {
    document.getElementById('modalGalleryImg').src = src;
    var myModal = new bootstrap.Modal(document.getElementById('galleryModal'));
    myModal.show();
}

function playEventTeaser() {
    var myModal = new bootstrap.Modal(document.getElementById('videoModal'));
    myModal.show();
}

function scrollToBooking() {
    const el = document.getElementById('booking-section-anchor');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth' });
    }
}

// Styling classes for stars
document.write('<style>.pointer{cursor:pointer;}</style>');
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
