<?php
include 'includes/conn.php';
include 'includes/functions.php';

if (!isLoggedIn()) {
    redirect('index.php');
}
validateSession();

$user_id = $_SESSION['user_id'];
$dashboard_url = getDashboardUrl();

// Handle "Mark all as read" action
if (isset($_POST['mark_all_read'])) {
    if (isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ?");
        $stmt->execute([$user_id]);
        $_SESSION['message'] = "All notifications have been marked as read.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Invalid request.";
        $_SESSION['message_type'] = "danger";
    }
    redirect('notifications.php');
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Get total notifications for pagination
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ?");
$total_stmt->execute([$user_id]);
$total_notifications = $total_stmt->fetchColumn();
$total_pages = ceil($total_notifications / $per_page);

// Fetch notifications for the current page
$notif_stmt = $pdo->prepare("
    SELECT n.*, u.first_name as sender_first, u.last_name as sender_last, u.profile_picture as sender_picture
    FROM notifications n
    JOIN users u ON n.sender_id = u.id
    WHERE n.recipient_id = :recipient_id 
    ORDER BY n.created_at DESC 
    LIMIT :limit OFFSET :offset
");
$notif_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$notif_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$notif_stmt->bindValue(':recipient_id', $user_id, PDO::PARAM_INT);
$notif_stmt->execute();
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$unread_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0");
$unread_count_stmt->execute([$user_id]);
$unread_count = $unread_count_stmt->fetchColumn();

$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == 1;

if (!$is_ajax):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Notifications - GradeView</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .notification-list-item { transition: background-color 0.3s ease; }
        .notification-list-item.unread { background-color: #eef2ff; border-left: 4px solid #6366f1; }
        .notification-list-item:hover { background-color: #f1f5f9; }
    </style>
</head>
<body>
    <div class="container py-5">
<?php endif; // End of non-ajax header ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold">All Notifications</h2>
                <?php if (!$is_ajax): ?>
                <a href="<?php echo $dashboard_url; ?>" class="text-decoration-none"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
                <?php endif; ?>
            </div>
            <?php if ($unread_count > 0 && !$is_ajax): ?>
            <form method="POST" action="notifications.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline-primary"><i class="fas fa-check-double me-2"></i>Mark All as Read (Page Reload)</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (isset($_SESSION['message']) && !$is_ajax): ?>
        <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($notifications)): ?>
                        <li class="list-group-item text-center text-muted p-5">You have no notifications.</li>
                    <?php else: foreach ($notifications as $notif): ?>
                        <li class="list-group-item p-3 notification-list-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                            <div class="d-flex align-items-start">
                                <img src="<?php echo htmlspecialchars($notif['sender_picture'] ?? 'image/default_avatar.png'); ?>" class="rounded-circle me-3 avatar-md" onerror="this.onerror=null;this.src='image/default_avatar.png';">
                                <div class="flex-grow-1"> 
                                    <p class="mb-1 <?php echo $notif['is_read'] ? 'text-muted' : 'fw-bold'; ?>"><?php echo highlightNotificationKeywords($notif['message']); ?></p>
                                    <small class="text-muted">From: <?php echo htmlspecialchars($notif['sender_first'] . ' ' . $notif['sender_last']); ?> &bull; <?php echo date('F j, Y, g:i a', strtotime($notif['created_at'])); ?></small>
                                </div>
                                <?php if (!$notif['is_read']): ?><span class="badge bg-primary rounded-pill ms-3">New</span><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav class="mt-4"><ul class="pagination justify-content-center">
            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page - 1; ?>">Previous</a></li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?><li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a></li><?php endfor; ?>
            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page + 1; ?>">Next</a></li>
        </ul></nav>
        <?php endif; ?>

<?php if (!$is_ajax): ?>
    </div>
    <?php include 'footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php endif; // End of non-ajax footer ?>