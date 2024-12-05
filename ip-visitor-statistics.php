<?php
/**
 * Plugin Name: IP Visitor Statistics
 * Description: Thống kê IP truy cập theo nhiều khoảng thời gian với bộ lọc URL và sắp xếp.
 * Version: 1.10
 * Author: Phạm Phú
 */

register_activation_hook(__FILE__, 'ivs_create_table');
function ivs_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ip_statistics';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(100) NOT NULL,
        visit_time DATETIME NOT NULL,
        url_visited TEXT NOT NULL,
        visit_count BIGINT(20) NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('wp_head', 'ivs_log_visitor_data');
function ivs_log_visitor_data() {
    if (is_admin()) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'ip_statistics';

    $ip_address = $_SERVER['REMOTE_ADDR'];
    $visit_time = current_time('mysql');
    $url_visited = esc_url_raw(home_url($_SERVER['REQUEST_URI']));

    $existing_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE ip_address = %s AND url_visited = %s",
        $ip_address, $url_visited
    ));

    if ($existing_entry) {
        $wpdb->update(
            $table_name,
            ['visit_count' => $existing_entry->visit_count + 1, 'visit_time' => $visit_time],
            ['id' => $existing_entry->id]
        );
    } else {
        $wpdb->insert(
            $table_name,
            ['ip_address' => $ip_address, 'visit_time' => $visit_time, 'url_visited' => $url_visited]
        );
    }
}

add_action('admin_menu', 'ivs_add_admin_page');
function ivs_add_admin_page() {
    add_menu_page(
        'IP Visitor Statistics',
        'IP Statistics',
        'manage_options',
        'ip-visitor-statistics',
        'ivs_render_admin_page'
    );
}

function ivs_render_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ip_statistics';

    $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'day';
    $selected_url = isset($_GET['url']) ? sanitize_text_field($_GET['url']) : '';
    $sort_order = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'DESC';
    $sort_column = isset($_GET['sort_column']) ? sanitize_text_field($_GET['sort_column']) : 'visit_time';

    $interval_map = [
        'day' => '1 DAY',
        '7days' => '7 DAY',
        '15days' => '15 DAY',
        '30days' => '30 DAY',
        '180days' => '180 DAY'
    ];

    $interval = $interval_map[$filter] ?? '1 DAY';
    $where_clause = "WHERE visit_time >= NOW() - INTERVAL $interval";

    if (!empty($selected_url)) {
        $where_clause .= $wpdb->prepare(" AND url_visited LIKE %s", '%' . $wpdb->esc_like($selected_url) . '%');
    }

    // Truy vấn chính kết hợp JOIN để lấy Visit Count IP
    $query = "
        SELECT 
            ips.ip_address, 
            ips.url_visited, 
            ips.visit_time, 
            SUM(ips.visit_count) AS visit_count, 
            IFNULL(ip_count.visit_count_ip, 0) AS visit_count_ip
        FROM $table_name ips
        LEFT JOIN (
            SELECT ip_address, SUM(visit_count) AS visit_count_ip
            FROM $table_name
            $where_clause
            GROUP BY ip_address
        ) AS ip_count
        ON ips.ip_address = ip_count.ip_address
        $where_clause
        GROUP BY ips.ip_address, ips.url_visited
        ORDER BY $sort_column $sort_order
    ";

    $results = $wpdb->get_results($query);

    echo '<div class="wrap">';
    echo "<h1>IP Visitor Statistics</h1>";

    // Hiển thị các tab thời gian
    $title_map = [
        'day' => 'Today',
        '7days' => 'Last 7 Days',
        '15days' => 'Last 15 Days',
        '30days' => 'Last 30 Days',
        '180days' => 'Last 180 Days'
    ];
    echo '<div style="margin-bottom: 20px;">';
    foreach ($title_map as $key => $title) {
        $active = $filter === $key ? 'button-primary' : 'button-secondary';
        $url = add_query_arg(['filter' => $key], '?page=ip-visitor-statistics');
        echo '<a href="' . esc_url($url) . '" class="button ' . $active . '">' . $title . '</a> ';
    }
    echo '</div>';

    // Bộ lọc URL
    echo '<form method="GET" style="margin-bottom: 20px;">';
    echo '<input type="hidden" name="page" value="ip-visitor-statistics">';
    echo '<input type="hidden" name="filter" value="' . esc_attr($filter) . '">';
    echo '<input type="hidden" name="sort" value="' . esc_attr($sort_order) . '">';
    echo '<input type="hidden" name="sort_column" value="' . esc_attr($sort_column) . '">';
    echo '<label for="url">Filter by URL:</label> ';
    echo '<input type="text" name="url" value="' . esc_attr($selected_url) . '" style="margin-right: 10px;">';
    echo '<button type="submit" class="button button-primary">Filter</button>';
    echo '</form>';

    // Tạo bảng dữ liệu với các cột sắp xếp
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    $columns = [
        'ip_address' => 'IP Address',
        'url_visited' => 'URL Visited',
        'visit_time' => 'Last Visit Time',
        'visit_count' => 'Visit Count',
        'visit_count_ip' => 'Visit Count IP'
    ];
    $base_url = '?page=ip-visitor-statistics&filter=' . $filter;

    foreach ($columns as $key => $label) {
        $sort_url = esc_url(add_query_arg([
            'sort_column' => $key,
            'sort' => toggle_sort($sort_order)
        ], $base_url));
        echo "<th><a href='$sort_url'>$label</a></th>";
    }
    echo '</tr></thead><tbody>';

    foreach ($results as $row) {
        echo "<tr>";
        echo "<td>{$row->ip_address}</td>";
        echo "<td>{$row->url_visited}</td>";
        echo "<td>{$row->visit_time}</td>";
        echo "<td>{$row->visit_count}</td>";
        echo "<td>{$row->visit_count_ip}</td>";
        echo "</tr>";
    }

    echo '</tbody></table>';
    echo '</div>';
}

function toggle_sort($current_sort) {
    return $current_sort === 'ASC' ? 'DESC' : 'ASC';
}


//-----------//
add_action('admin_menu', 'ivs_add_subpages');
function ivs_add_subpages() {
    add_submenu_page(
        'ip-visitor-statistics',         // Slug của trang cha
        'Search IP History',             // Tiêu đề trang con
        'Search IP',                     // Tên hiển thị trên menu
        'manage_options',                // Quyền truy cập
        'search-ip-history',             // Slug của trang con
        'ivs_render_search_ip_page'      // Hàm hiển thị nội dung trang
    );
}

function ivs_render_search_ip_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ip_statistics';

    // Kiểm tra nếu người dùng nhập IP để tìm kiếm
    $search_ip = isset($_POST['search_ip']) ? sanitize_text_field($_POST['search_ip']) : '';

    echo '<div class="wrap">';
    echo '<h1>Search IP History</h1>';
    echo '<form method="POST">';
    echo '<label for="search_ip">Enter IP Address:</label> ';
    echo '<input type="text" name="search_ip" id="search_ip" value="' . esc_attr($search_ip) . '" style="margin-right: 10px;">';
    echo '<button type="submit" class="button button-primary">Search</button>';
    echo '</form>';

    // Nếu IP đã được nhập, truy vấn dữ liệu từ CSDL
    if (!empty($search_ip)) {
        $query = $wpdb->prepare("
            SELECT ip_address, url_visited, visit_time 
            FROM $table_name 
            WHERE ip_address = %s 
            ORDER BY visit_time DESC
        ", $search_ip);

        $results = $wpdb->get_results($query);

        if ($results) {
            echo '<h2>Results for IP: ' . esc_html($search_ip) . '</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>IP Address</th>';
            echo '<th>URL Visited</th>';
            echo '<th>Last Visit Time</th>';
            echo '</tr></thead><tbody>';

            foreach ($results as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->ip_address) . '</td>';
                echo '<td>' . esc_html($row->url_visited) . '</td>';
                echo '<td>' . esc_html($row->visit_time) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No records found for IP: ' . esc_html($search_ip) . '</p>';
        }
    }

    echo '</div>';
}
