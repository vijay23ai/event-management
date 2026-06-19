<?php
$page_title = "Events Calendar";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Fetch all approved events
try {
    $stmt = $pdo->query("SELECT id, title, category, date_time FROM events WHERE status = 'approved' ORDER BY date_time ASC");
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Map categories to modern hex colors
$category_colors = [
    'Concert' => '#6366f1',  // Indigo
    'Workshop' => '#10b981', // Emerald
    'Sports' => '#f59e0b',   // Amber
    'Seminar' => '#3b82f6',  // Blue
    'Festival' => '#ec4899', // Pink
    'Meetup' => '#8b5cf6'    // Violet
];

// Build FullCalendar events array
$calendar_events = [];
foreach ($events as $e) {
    $color = $category_colors[$e['category']] ?? '#6b7280';
    $calendar_events[] = [
        'id' => $e['id'],
        'title' => "[" . $e['category'] . "] " . $e['title'],
        'start' => date('Y-m-d\TH:i:s', strtotime($e['date_time'])),
        'url' => '/event.php?id=' . $e['id'],
        'backgroundColor' => $color,
        'borderColor' => $color,
        'textColor' => '#ffffff'
    ];
}

// Additional styles for FullCalendar inclusion
$additional_styles = '
<!-- FullCalendar CDN -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
';

require_once __DIR__ . '/includes/header.php';
?>

<div class="row mb-5">
    <div class="col-12">
        <div class="glass-panel p-5">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
                <div>
                    <h2 class="fw-bold mb-1">Calendar Module</h2>
                    <p class="text-secondary mb-0">Browse events and activities by day, week, or month</p>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0">
                    <?php foreach ($category_colors as $cat => $color): ?>
                        <span class="badge badge-custom d-flex align-items-center gap-2" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); color: var(--text-primary);">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $color; ?>;"></span>
                            <?php echo $cat; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Calendar DOM element -->
            <div id="calendar-container" class="p-3 bg-dark rounded-4" style="background: rgba(15, 23, 42, 0.6) !important; border: 1px solid var(--glass-border);">
                <div id="calendar"></div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        height: 'auto',
        events: <?php echo json_encode($calendar_events); ?>,
        eventClick: function(info) {
            if (info.event.url) {
                window.location.href = info.event.url;
                info.jsEvent.preventDefault(); // Prevents default browser redirect behavior
            }
        },
        themeSystem: 'standard'
    });
    calendar.render();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
