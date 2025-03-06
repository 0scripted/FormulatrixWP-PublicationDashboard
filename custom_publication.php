<?php
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit();
}

// Get filter values
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$application = isset($_GET['application']) ? trim($_GET['application']) : '';
$product = isset($_GET['product']) ? trim($_GET['product']) : '';
$date_filter = isset($_GET['date_filter']) ? trim($_GET['date_filter']) : 'all';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;

// Get data for dropdowns
$stmt = $conn->query("SELECT DISTINCT name FROM wp_teachpress_authors ORDER BY name");
$applications = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $conn->query("SELECT DISTINCT name FROM wp_teachpress_tags ORDER BY name");
$products = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get min and max years for date range
$stmt = $conn->query("SELECT MIN(YEAR(date)) as min_year, MAX(YEAR(date)) as max_year FROM wp_teachpress_pub");
$date_range = $stmt->fetch(PDO::FETCH_ASSOC);
$min_year = $date_range['min_year'] ?? date('Y') - 10;
$max_year = $date_range['max_year'] ?? date('Y');

// If no dates selected, default to full range
if (empty($start_date)) $start_date = $min_year;
if (empty($end_date)) $end_date = $max_year;

// Build base query for publications
$baseQuery = "FROM wp_teachpress_pub p
              LEFT JOIN (SELECT pub_id, GROUP_CONCAT(DISTINCT a.name ORDER BY a.name ASC SEPARATOR ', ') as applications
                        FROM wp_teachpress_rel_pub_auth pa
                        JOIN wp_teachpress_authors a ON pa.author_id = a.author_id
                        GROUP BY pub_id) apps ON p.pub_id = apps.pub_id
              LEFT JOIN (SELECT pub_id, GROUP_CONCAT(DISTINCT t.name ORDER BY t.name ASC SEPARATOR ', ') as products
                        FROM wp_teachpress_relation pr
                        JOIN wp_teachpress_tags t ON pr.tag_id = t.tag_id
                        GROUP BY pub_id) tags ON p.pub_id = tags.pub_id";

$conditions = [];
$params = [];

if ($application) {
    $conditions[] = "FIND_IN_SET(?, apps.applications)";
    $params[] = $application;
}

if ($product) {
    $conditions[] = "FIND_IN_SET(?, tags.products)";
    $params[] = $product;
}

if ($start_date && $end_date) {
    $conditions[] = "YEAR(p.date) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

if ($search) {
    $conditions[] = "(p.title LIKE ? OR p.abstract LIKE ? OR p.author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";

$countQuery = "SELECT COUNT(DISTINCT p.pub_id) " . $baseQuery . $whereClause;
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$total_publications = $stmt->fetchColumn();
$total_pages = ceil($total_publications / $per_page);

// Ensure page is within valid range
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $per_page;

$query = "SELECT p.*, tags.products as tag_names, apps.applications " . $baseQuery . $whereClause . " ORDER BY p.date DESC LIMIT " . $offset . ", " . $per_page;
$stmt = $conn->prepare($query);
$stmt->execute($params);
$publications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .publication-card {
            margin-bottom: 20px;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .publication-title {
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 1.1rem;
            font-weight: 500;
        }
        .publication-meta {
            color: #6c757d;
            margin-bottom: 12px;
            font-size: 0.9rem;
        }
        .meta-separator {
            margin: 0 8px;
            color: #adb5bd;
            display: inline-block;
        }
        .abstract-content {
            margin-top: 10px;
            color: #495057;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .related-solution-inline {
            color: #6c757d;
            font-size: 0.9rem;
            display: inline-block;
        }
        .abstract-truncated {
            display: block;
        }
        .abstract-full {
            display: none;
        }
        .show-more-btn {
            color: #f7a246;
            cursor: pointer;
            display: inline-block;
            margin-left: 5px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .show-more-btn:hover {
            text-decoration: underline;
            color: #f7a246;
        }
        .doi-link {
            color: #f7a246;
            text-decoration: none;
        }
        .doi-link:hover {
            text-decoration: underline;
        }
        .pagination {
            margin-top: 30px;
            justify-content: center;
        }
        .page-link {
            color: #495057;
            border-color: #dee2e6;
            background-color: #fff;
            padding: 0.5rem 0.75rem;
        }
        .page-link:hover {
            color: #f7a246;
            background-color: #e9ecef;
        }
        .page-item.active .page-link {
            background-color: #f7a246;
            border-color: #f7a246;
            color: #fff;
        }
        .page-item.disabled .page-link {
            color: #6c757d;
            pointer-events: none;
            background-color: #fff;
        }
        .citation-count {
            font-size: 1rem;
            color: #2c3e50;
            margin-bottom: 1.5rem;
        }
        .date-filter-group {
            margin-bottom: 1rem;
        }
        .filters-row {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .filter-group {
            flex: 1;
        }
        .filter-label {
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            color: #212529;
        }
        .reset-btn {
            width: 100px;
            margin-top: 28px; /* Align with the dropdowns */
        }
        .date-range-container {
            display: none;
            margin-top: 0.5rem;
        }
        .date-range-container.show {
            display: block;
        }
        .date-range-row {
            display: flex;
            gap: 0.5rem;
        }
        .date-select {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="citation-count">
            <?php echo $total_publications; ?> Citations
        </div>

        <form id="filterForm" method="GET" class="mb-4">
            <div class="mb-4">
                <input type="text" class="form-control" id="search" name="search"
                       placeholder="Search publications..." value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filters-row">
                <!-- Applications filter -->
                <div class="filter-group">
                    <div class="filter-label">Applications</div>
                    <select class="form-select" id="application" name="application">
                        <option value="">All Applications</option>
                        <?php foreach($applications as $app): ?>
                            <option value="<?php echo htmlspecialchars($app); ?>"
                                <?php echo $application === $app ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($app); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Products filter -->
                <div class="filter-group">
                    <div class="filter-label">Products</div>
                    <select class="form-select" id="product" name="product">
                        <option value="">All Products</option>
                        <?php foreach($products as $prod): ?>
                            <option value="<?php echo htmlspecialchars($prod); ?>"
                                <?php echo $product === $prod ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prod); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date filter -->
                <div class="filter-group">
                    <div class="filter-label">Date</div>
                    <select class="form-select" id="date_filter" name="date_filter" onchange="toggleDateRange(this.value)">
                        <option value="all" <?php echo ($date_filter === 'all') ? 'selected' : ''; ?>>All Years</option>
                        <option value="custom" <?php echo ($date_filter === 'custom') ? 'selected' : ''; ?>>Custom Range</option>
                    </select>

                    <div class="date-range-container <?php echo ($date_filter === 'custom') ? 'show' : ''; ?>">
                        <div class="date-range-row">
                            <div class="date-select">
                                <select class="form-select" id="start_date" name="start_date">
                                    <option value="">From</option>
                                    <?php for($year = $max_year; $year >= $min_year; $year--): ?>
                                        <option value="<?php echo $year; ?>"
                                            <?php echo $start_date == $year ? 'selected' : ''; ?>>
                                            <?php echo $year; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="date-select">
                                <select class="form-select" id="end_date" name="end_date">
                                    <option value="">To</option>
                                    <?php for($year = $max_year; $year >= $min_year; $year--): ?>
                                        <option value="<?php echo $year; ?>"
                                            <?php echo $end_date == $year ? 'selected' : ''; ?>>
                                            <?php echo $year; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reset button -->
                <button type="button" class="btn btn-outline-secondary reset-btn" onclick="resetFilters()">Reset</button>
            </div>
        </form>

        <!-- Publications List -->
        <div id="publicationsList">
            <?php if (empty($publications)): ?>
                <div class="alert alert-info">No publications found matching your criteria.</div>
            <?php else: ?>
                <?php foreach($publications as $pub): ?>
                    <?php
                        // Get the first 100 words of the abstract
                        $words = str_word_count($pub['abstract'], 1);
                        $truncated = implode(' ', array_slice($words, 0, 100));
                        $isLong = count($words) > 100;

                        // Get tag name from crossref field
                        $tags = explode(',', $pub['crossref']);
                        $tagNames = array_map('trim', $tags);
                    ?>
                    <div class="publication-card">
                        <div class="publication-title"><?php echo htmlspecialchars($pub['title']); ?></div>
                        <div class="publication-meta">
                            <?php echo htmlspecialchars($pub['author']); ?>
                            <span class="meta-separator">|</span>
                            <?php echo htmlspecialchars($pub['crossref']); ?>
                            <?php if($pub['url']): ?>
                                <span class="meta-separator">|</span>
                                <a href="<?php echo htmlspecialchars($pub['url']); ?>" target="_blank" class="doi-link">Link</a>
                            <?php endif; ?>
                        </div>
                        <div class="abstract-content">
                            <div class="abstract-truncated">
                                <?php echo nl2br(htmlspecialchars($truncated)); ?>
                                <?php if ($isLong): ?>
                                    <span class="show-more-btn" onclick="toggleAbstract(this)">... More</span>
                                    <span class="meta-separator">|</span>
                                    <span class="related-solution-inline">Related Solutions: <?php
                                        $tags = explode(',', $pub['tag_names']);
                                        $tagLinks = array_map(function($tag) {
                                            $tag = trim($tag);
                                            return sprintf('<a href="#" onclick="setProductFilter(\'%s\'); return false;" class="solution-link">%s</a>',
                                                htmlspecialchars($tag),
                                                htmlspecialchars($tag)
                                            );
                                        }, $tags);
                                        echo implode(', ', $tagLinks);
                                    ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($isLong): ?>
                                <div class="abstract-full">
                                    <?php echo nl2br(htmlspecialchars($pub['abstract'])); ?>
                                    <span class="show-more-btn" onclick="toggleAbstract(this)">Less</span>
                                    <span class="meta-separator">|</span>
                                    <span class="related-solution-inline">Related Solutions: <?php echo htmlspecialchars(str_replace(',', ', ', $pub['tag_names'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <?php $params = $_GET;
                    unset($params['page']); // Remove page from params to avoid duplication

                    // Previous page link
                    $prevClass = ($page <= 1) ? 'disabled' : '';
                    $prevPage = max(1, $page - 1);
                    $prevParams = array_merge($params, ['page' => $prevPage]);
                    $prevUrl = '?' . http_build_query($prevParams);

                    // Next page link
                    $nextClass = ($page >= $total_pages) ? 'disabled' : '';
                    $nextPage = min($total_pages, $page + 1);
                    $nextParams = array_merge($params, ['page' => $nextPage]);
                    $nextUrl = '?' . http_build_query($nextParams);

                    // Show page numbers
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);

                    // Build pagination HTML
                    ?>
                    <nav aria-label="Publication pagination">
                        <ul class="pagination">
                            <li class="page-item <?php echo $prevClass; ?>">
                                <a class="page-link" onclick="navigateToPage(<?php echo $prevPage; ?>); return false;" href="#" <?php echo $prevClass ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>Previous</a>
                            </li>
                            <?php
                            // First page and ellipsis
                            if ($start > 1) {
                                echo '<li class="page-item"><a class="page-link" onclick="navigateToPage(1); return false;" href="#">1</a></li>';
                                if ($start > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }

                            // Page numbers
                            for ($i = $start; $i <= $end; $i++) {
                                $activeClass = ($i == $page) ? 'active' : '';
                                echo '<li class="page-item ' . $activeClass . '"><a class="page-link" onclick="navigateToPage(' . $i . '); return false;" href="#">' . $i . '</a></li>';
                            }

                            // Last page and ellipsis
                            if ($end < $total_pages) {
                                if ($end < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" onclick="navigateToPage(' . $total_pages . '); return false;" href="#">' . $total_pages . '</a></li>';
                            }
                            ?>
                            <li class="page-item <?php echo $nextClass; ?>">
                                <a class="page-link" onclick="navigateToPage(<?php echo $nextPage; ?>); return false;" href="#" <?php echo $nextClass ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }
        function setProductFilter(product) {
            document.getElementById('product').value = product;
            submitForm();
        }
        // Function to submit the form
        function submitForm() {
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page');
            if (page) {
                const pageInput = document.createElement('input');
                pageInput.type = 'hidden';
                pageInput.name = 'page';
                pageInput.value = page;
                document.getElementById('filterForm').appendChild(pageInput);
            }
            document.getElementById('filterForm').submit();
        }

        // Create debounced version for search
        const debouncedSubmit = debounce(submitForm, 500);

        // Add event listeners
        document.querySelector('input[name="search"]').addEventListener('input', debouncedSubmit);
        document.querySelector('select[name="application"]').addEventListener('change', submitForm);
        document.querySelector('select[name="product"]').addEventListener('change', submitForm);

        // Reset button handler
        function resetFilters() {
            document.getElementById('search').value = '';
            document.getElementById('application').value = '';
            document.getElementById('product').value = '';
            document.getElementById('date_filter').value = 'all';
            document.getElementById('start_date').value = '';
            document.getElementById('end_date').value = '';
            document.querySelector('.date-range-container').classList.remove('show');
            document.getElementById('filterForm').submit();
        }

        function toggleDateRange(value) {
            const container = document.querySelector('.date-range-container');
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');

            if (value === 'custom') {
                container.classList.add('show');
            } else {
                container.classList.remove('show');
                startDate.value = '';
                endDate.value = '';
                document.getElementById('filterForm').submit();
            }
        }

        // Date range change handlers
        document.getElementById('start_date').addEventListener('change', function() {
            if (this.value && document.getElementById('end_date').value) {
                document.getElementById('filterForm').submit();
            }
        });

        document.getElementById('end_date').addEventListener('change', function() {
            if (this.value && document.getElementById('start_date').value) {
                document.getElementById('filterForm').submit();
            }
        });

        function navigateToPage(pageNum) {
            const form = document.getElementById('filterForm');
            const pageInput = document.createElement('input');
            pageInput.type = 'hidden';
            pageInput.name = 'page';
            pageInput.value = pageNum;
            form.appendChild(pageInput);
            form.submit();
        }

        // Function to toggle abstract visibility
        function toggleAbstract(btn) {
            const card = btn.closest('.publication-card');
            const truncated = card.querySelector('.abstract-truncated');
            const full = card.querySelector('.abstract-full');

            if (truncated.style.display === 'none') {
                truncated.style.display = 'block';
                full.style.display = 'none';
            } else {
                truncated.style.display = 'none';
                full.style.display = 'block';
            }
        }
    </script>
</body>
</html>
